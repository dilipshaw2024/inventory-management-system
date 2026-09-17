<?php

namespace App\Http\Controllers;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\ReconciliationReview;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\InventoryReconciliationSnapshot;
use App\Services\InventorySnapshotService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['to' => ['nullable', 'date']]);
        $to = $data['to'] ?? now()->toDateString();
        $rows = $this->rows($to);
        $reviews = ReconciliationReview::with('reviewer')->latest('reviewed_at')->limit(20)->get();
        $snapshots = InventoryReconciliationSnapshot::with('creator')->latest('as_of_date')->latest('id')->limit(20)->get();
        return view('backend.accounting.reconciliation', compact('rows', 'to', 'reviews', 'snapshots'));
    }

    public function captureSnapshot(Request $request, InventorySnapshotService $snapshots)
    {
        $data = $request->validate(['as_of_date' => ['required', 'date']]);
        $snapshot = $snapshots->capture(auth()->user()?->company_id, $data['as_of_date'], auth()->id());
        return back()->with(['message' => 'Inventory snapshot #'.$snapshot->id.' is available for '.$snapshot->as_of_date->toDateString().'.', 'alert-type' => 'success']);
    }

    public function storeReview(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $rows = $this->rows($data['to']);
        $status = collect($rows)->contains(fn (array $row): bool => $row['journal'] === null)
            ? 'needs_mapping'
            : (collect($rows)->contains(fn (array $row): bool => abs((float) $row['variance']) >= 0.01) ? 'variance' : 'reconciled');
        $review = ReconciliationReview::create(['company_id' => auth()->user()?->company_id, 'as_of_date' => $data['to'], 'status' => $status, 'rows' => $rows, 'notes' => $data['notes'] ?? null, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        app(\App\Services\AuditService::class)->record('reconciliation.reviewed', $review, null, $review->toArray());
        return back()->with(['message' => 'Reconciliation review saved as '.str_replace('_', ' ', $status).'.', 'alert-type' => $status === 'reconciled' ? 'success' : 'warning']);
    }

    private function rows(string $to): array
    {
        $companyId = auth()->user()?->company_id;
        $mapping = AccountMapping::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->get()->unique('mapping_key')->keyBy('mapping_key');
        $accountIds = $mapping->pluck('account_id')->filter()->values();
        $accountTypes = ChartOfAccount::whereIn('id', $accountIds)->pluck('account_type', 'id');
        $journalBalances = JournalEntry::where('status', 'posted')->whereDate('date', '<=', $to)->with(['lines' => fn ($query) => $query->whereIn('account_id', $accountIds)])->get()->flatMap->lines->groupBy('account_id')->map(fn ($lines): float => (float) $lines->sum('debit') - (float) $lines->sum('credit'));
        $ap = (float) PurchaseInvoice::where('status', 'approved')->whereDate('invoice_date', '<=', $to)->sum('total_amount') - (float) SupplierPayment::where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $to)->whereNotNull('purchase_invoice_id')->sum('amount') - (float) SupplierPaymentAllocation::whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->whereDate('allocated_at', '<=', $to)->sum('amount');
        $ar = (float) Invoice::where('status', 1)->whereDate('date', '<=', $to)->sum('total_amount') - (float) Payment::where('is_reversed', false)->whereDate('created_at', '<=', $to)->sum('paid_amount');
        $snapshot = InventoryReconciliationSnapshot::whereDate('as_of_date', $to)->first();
        $inventory = $snapshot
            ? (float) collect($snapshot->rows ?? [])->sum(fn (array $row): float => (float) ($row['balance_value'] ?? 0))
            : (float) InventoryMovement::whereDate('posted_at', '<=', $to)->get()->sum(function (InventoryMovement $movement): float {
                $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
                $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
                $sign = in_array($movement->movement_type, $inbound, true) ? 1 : (in_array($movement->movement_type, $outbound, true) ? -1 : 0);
                return $sign * (float) $movement->quantity * (float) ($movement->unit_cost ?? 0);
            });
        return collect([
            ['name' => 'Accounts payable', 'mapping' => 'accounts_payable', 'subledger' => $ap],
            ['name' => 'Accounts receivable', 'mapping' => 'accounts_receivable', 'subledger' => $ar],
            ['name' => 'Inventory', 'mapping' => 'inventory', 'subledger' => $inventory],
        ])->map(function (array $row) use ($mapping, $journalBalances, $accountTypes): array {
            $row['account'] = $mapping->get($row['mapping'])?->account_id;
            $raw = $row['account'] ? (float) $journalBalances->get($row['account'], 0) : null;
            $row['journal'] = $raw === null ? null : (in_array($accountTypes->get($row['account']), ['liability', 'income'], true) ? -$raw : $raw);
            $row['variance'] = $row['journal'] === null ? null : $row['subledger'] - $row['journal'];
            return $row;
        })->all();
    }
}
