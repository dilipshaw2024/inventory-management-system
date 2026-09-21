<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\SupplierCreditNote;
use App\Services\AuditService;
use App\Services\AutomaticAccountingService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierCreditNoteIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $notes = SupplierCreditNote::with(['supplier', 'claim', 'purchaseInvoice', 'journalEntry'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($notes, $request, 'accounting.supplier-credit-notes', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'credit_no' => ['nullable', 'string', 'max:80', Rule::unique('supplier_credit_notes', 'credit_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_claim_id' => ['nullable', 'integer', Rule::exists('supplier_claims', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'purchase_invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'credit_date' => ['required', 'date'], 'subtotal_amount' => ['required', 'numeric', 'min:0'], 'tax_amount' => ['nullable', 'numeric', 'min:0'], 'description' => ['nullable', 'string', 'max:3000'],
        ]);
        $total = round((float) $data['subtotal_amount'] + (float) ($data['tax_amount'] ?? 0), 6);
        if ($total <= 0) abort(422, 'Credit note total must be greater than zero.');
        if (empty($data['supplier_claim_id']) && empty($data['purchase_invoice_id'])) abort(422, 'A supplier claim or purchase invoice is required for a credit note.');
        if (!empty($data['external_reference'])) {
            $existing = SupplierCreditNote::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['supplier', 'claim', 'purchaseInvoice', 'journalEntry']), 'status' => 'duplicate_ignored']);
        }
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($data['supplier_id']);
        $claim = !empty($data['supplier_claim_id']) ? SupplierClaim::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($data['supplier_claim_id']) : null;
        if ($claim && ((int) $claim->supplier_id !== (int) $supplier->id || !in_array($claim->status, ['accepted', 'partially_settled'], true))) abort(422, 'Credit notes require an accepted or partially settled claim for the selected supplier.');
        $invoice = !empty($data['purchase_invoice_id']) ? PurchaseInvoice::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($data['purchase_invoice_id']) : null;
        if ($invoice && (int) $invoice->supplier_id !== (int) $supplier->id) abort(422, 'Credit note invoice does not match the selected supplier.');
        if ($claim && (float) $claim->settled_amount + $total > (float) $claim->claim_amount + 0.000001) abort(422, 'Credit note exceeds the remaining supplier claim amount.');
        $note = DB::transaction(function () use ($data, $companyId, $request, $total): SupplierCreditNote {
            $note = SupplierCreditNote::create([
                'company_id' => $companyId, 'supplier_id' => $data['supplier_id'], 'supplier_claim_id' => $data['supplier_claim_id'] ?? null, 'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'credit_no' => $data['credit_no'] ?? app(NumberingSequenceService::class)->nextOrFallback('supplier_credit_note', 'SCN-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'external_reference' => $data['external_reference'] ?? null, 'credit_date' => $data['credit_date'], 'subtotal_amount' => $data['subtotal_amount'], 'tax_amount' => $data['tax_amount'] ?? 0, 'total_amount' => $total, 'description' => $data['description'] ?? null, 'created_by' => $request->user()?->id,
            ]);
            app(AuditService::class)->record('supplier_credit_note.created', $note, null, $note->toArray() + ['api' => true]);
            return $note;
        });
        return response()->json(['data' => $note->load(['supplier', 'claim', 'purchaseInvoice']), 'status' => 'pending'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(SupplierCreditNote::class, $id);
        $note = DB::transaction(function () use ($request, $id): SupplierCreditNote {
            $note = SupplierCreditNote::with(['claim', 'purchaseInvoice'])->lockForUpdate()->findOrFail($id);
            if ($note->status !== 'pending') throw new \RuntimeException('This credit note has already been processed.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($note);
            $journal = null;
            if ($note->claim) {
                $claim = SupplierClaim::lockForUpdate()->findOrFail($note->supplier_claim_id);
                $remaining = (float) $claim->claim_amount - (float) $claim->settled_amount;
                if (!in_array($claim->status, ['accepted', 'partially_settled'], true) || (float) $note->total_amount > $remaining + 0.000001) throw new \RuntimeException('Credit note no longer fits the remaining supplier claim.');
                if ($note->purchase_invoice_id && $claim->purchase_invoice_id && (int) $note->purchase_invoice_id !== (int) $claim->purchase_invoice_id) throw new \RuntimeException('Credit note invoice does not match the claim invoice.');
                if ($note->purchase_invoice_id && !$claim->purchase_invoice_id) $claim->purchase_invoice_id = $note->purchase_invoice_id;
                $newAmount = (float) $claim->settled_amount + (float) $note->total_amount;
                $newStatus = $newAmount + 0.000001 >= (float) $claim->claim_amount ? 'settled' : 'partially_settled';
                $claim->update(['purchase_invoice_id' => $claim->purchase_invoice_id ?: $note->purchase_invoice_id, 'settled_amount' => $newAmount, 'status' => $newStatus, 'settlement_reference' => $note->credit_no, 'settled_by' => $request->user()?->id, 'settled_at' => now()]);
                $journal = app(AutomaticAccountingService::class)->postSupplierClaimSettlement($claim, (float) $note->total_amount);
                if ($journal) $claim->update(['settlement_posted_amount' => (float) $claim->settlement_posted_amount + (float) $note->total_amount, 'settlement_journal_id' => $journal->id]);
            } elseif ($note->purchase_invoice) {
                $journal = app(AutomaticAccountingService::class)->postSupplierCreditNote($note, (float) $note->total_amount);
            }
            $note->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'journal_entry_id' => $journal?->id]);
            app(AuditService::class)->record('supplier_credit_note.approved', $note, ['status' => 'pending'], $note->fresh()->only(['status', 'approved_by', 'approved_at', 'journal_entry_id']));
            return $note->fresh();
        });
        return response()->json(['data' => $note->load(['supplier', 'claim', 'purchaseInvoice', 'journalEntry']), 'status' => $note->status]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:3000']]);
        $note = DB::transaction(function () use ($data, $request, $id): SupplierCreditNote {
            $note = SupplierCreditNote::lockForUpdate()->findOrFail($id);
            if ($note->status !== 'pending') throw new \RuntimeException('Only pending credit notes can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($note);
            $note->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
            app(AuditService::class)->record('supplier_credit_note.rejected', $note, ['status' => 'pending'], $note->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $note->fresh();
        });
        return response()->json(['data' => $note->load(['supplier', 'claim', 'purchaseInvoice']), 'status' => $note->status]);
    }
}
