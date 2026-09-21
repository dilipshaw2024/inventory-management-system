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
use App\Services\AccountingReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['to' => ['nullable', 'date']]);
        $to = $data['to'] ?? now()->toDateString();
        $rows = app(AccountingReconciliationService::class)->rows(auth()->user()?->company_id, $to);
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
        $rows = app(AccountingReconciliationService::class)->rows(auth()->user()?->company_id, $data['to']);
        $status = collect($rows)->contains(fn (array $row): bool => $row['journal'] === null)
            ? 'needs_mapping'
            : (collect($rows)->contains(fn (array $row): bool => abs((float) $row['variance']) >= 0.01) ? 'variance' : 'reconciled');
        $review = ReconciliationReview::create(['company_id' => auth()->user()?->company_id, 'as_of_date' => $data['to'], 'status' => $status, 'rows' => $rows, 'notes' => $data['notes'] ?? null, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        app(\App\Services\AuditService::class)->record('reconciliation.reviewed', $review, null, $review->toArray());
        return back()->with(['message' => 'Reconciliation review saved as '.str_replace('_', ' ', $status).'.', 'alert-type' => $status === 'reconciled' ? 'success' : 'warning']);
    }

    private function rows(string $to): array
    {
        return app(AccountingReconciliationService::class)->rows(auth()->user()?->company_id, $to);
    }
}
