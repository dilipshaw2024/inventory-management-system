<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Services\AuditService;
use App\Services\AutomaticAccountingService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerCreditNoteIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $notes = CustomerCreditNote::with(['customer', 'invoice', 'journalEntry'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($notes, $request, 'accounting.customer-credit-notes', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'credit_no' => ['nullable', 'string', 'max:80', Rule::unique('customer_credit_notes', 'credit_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'credit_date' => ['required', 'date'], 'subtotal_amount' => ['required', 'numeric', 'min:0'], 'tax_amount' => ['nullable', 'numeric', 'min:0'], 'description' => ['nullable', 'string', 'max:3000'],
        ]);
        $total = round((float) $data['subtotal_amount'] + (float) ($data['tax_amount'] ?? 0), 6);
        if ($total <= 0) abort(422, 'Credit note total must be greater than zero.');
        if (!empty($data['external_reference']) && ($existing = CustomerCreditNote::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing->load(['customer', 'invoice', 'journalEntry']), 'status' => 'duplicate_ignored', 'idempotent' => true]);
        $invoice = Invoice::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($data['invoice_id']);
        if ((int) $invoice->customer_id !== (int) $data['customer_id']) abort(422, 'Credit note invoice does not match the selected customer.');
        if (!in_array($invoice->status, [1, 'approved'], true)) abort(422, 'Customer credit notes require an approved invoice.');
        $already = (float) CustomerCreditNote::where('invoice_id', $invoice->id)->where('status', 'approved')->sum('total_amount');
        if ($already + $total > (float) $invoice->total_amount + 0.000001) abort(422, 'Credit note exceeds the remaining invoice amount.');
        $note = DB::transaction(function () use ($data, $companyId, $request, $total): CustomerCreditNote {
            $note = CustomerCreditNote::create([
                'company_id' => $companyId, 'customer_id' => $data['customer_id'], 'invoice_id' => $data['invoice_id'],
                'credit_no' => $data['credit_no'] ?? app(NumberingSequenceService::class)->nextOrFallback('customer_credit_note', 'CCN-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'external_reference' => $data['external_reference'] ?? null, 'credit_date' => $data['credit_date'], 'subtotal_amount' => $data['subtotal_amount'], 'tax_amount' => $data['tax_amount'] ?? 0, 'total_amount' => $total, 'description' => $data['description'] ?? null, 'created_by' => $request->user()?->id,
            ]);
            app(AuditService::class)->record('customer_credit_note.created', $note, null, $note->toArray() + ['api' => true]);
            return $note;
        });
        return response()->json(['data' => $note->load(['customer', 'invoice']), 'status' => 'pending'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(CustomerCreditNote::class, $id);
        $note = DB::transaction(function () use ($request, $id): CustomerCreditNote {
            $note = CustomerCreditNote::lockForUpdate()->findOrFail($id);
            if ($note->status !== 'pending') throw new \RuntimeException('This credit note has already been processed.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($note);
            $already = (float) CustomerCreditNote::where('invoice_id', $note->invoice_id)->where('status', 'approved')->where('id', '<>', $note->id)->sum('total_amount');
            if ($already + (float) $note->total_amount > (float) $note->invoice->total_amount + 0.000001) throw new \RuntimeException('Credit note no longer fits the remaining invoice amount.');
            $journal = app(AutomaticAccountingService::class)->postCustomerCreditNote($note, (float) $note->total_amount);
            $note->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'journal_entry_id' => $journal?->id]);
            app(AuditService::class)->record('customer_credit_note.approved', $note, ['status' => 'pending'], $note->fresh()->only(['status', 'approved_by', 'approved_at', 'journal_entry_id']));
            return $note->fresh();
        });
        return response()->json(['data' => $note->load(['customer', 'invoice', 'journalEntry']), 'status' => $note->status]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:3000']]);
        $note = DB::transaction(function () use ($data, $request, $id): CustomerCreditNote {
            $note = CustomerCreditNote::lockForUpdate()->findOrFail($id);
            if ($note->status !== 'pending') throw new \RuntimeException('Only pending customer credit notes can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($note);
            $note->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
            app(AuditService::class)->record('customer_credit_note.rejected', $note, ['status' => 'pending'], $note->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $note->fresh();
        });
        return response()->json(['data' => $note->load(['customer', 'invoice']), 'status' => $note->status]);
    }
}
