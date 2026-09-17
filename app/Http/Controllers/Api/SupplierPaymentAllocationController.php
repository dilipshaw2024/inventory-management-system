<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\AuditService;
use App\Services\SupplierPaymentService;
use App\Services\CurrencyConversionService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierPaymentAllocationController extends Controller
{
    public function storePayment(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('supplier_payments', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'purchase_invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'in:cash,bank,card,transfer,other'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(SupplierPayment::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('supplier', 'purchaseInvoice'), 'status' => 'duplicate_ignored']);
        }
        $supplier = $this->companyScope(Supplier::query())->findOrFail($data['supplier_id']);
        $invoice = !empty($data['purchase_invoice_id']) ? $this->companyScope(PurchaseInvoice::query())->findOrFail($data['purchase_invoice_id']) : null;
        if ($invoice && (int) $invoice->supplier_id !== (int) $supplier->id) abort(422, 'Payment supplier does not match the selected invoice.');
        $currency = strtoupper($data['currency_code'] ?? ($invoice?->currency_code ?: ($request->user()?->company?->base_currency ?? 'USD')));
        $rate = $data['exchange_rate'] ?? app(CurrencyConversionService::class)->rate($currency, strtoupper($request->user()?->company?->base_currency ?? 'USD'), $data['payment_date']);
        $payment = SupplierPayment::create([
            'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'payment_no' => app(NumberingSequenceService::class)->nextOrFallback('supplier_payment', 'PAY-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
            'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice?->id, 'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'method' => $data['method'], 'currency_code' => $currency, 'exchange_rate' => $rate, 'base_amount' => (float) $data['amount'] * (float) $rate, 'reference' => $data['reference'] ?? null, 'description' => $data['description'] ?? null, 'status' => 'pending', 'created_by' => $request->user()?->id,
        ]);
        app(AuditService::class)->record('supplier_payment.created', $payment, null, $payment->toArray());
        return response()->json(['data' => $payment->load('supplier', 'purchaseInvoice'), 'status' => 'pending_approval'], 201);
    }

    public function approvePayment(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(SupplierPayment::class, $id);
        $payment = $this->companyScope(SupplierPayment::query())->findOrFail($id);
        if ($payment->status !== 'pending') throw new \RuntimeException('This payment has already been processed.');
        app(\App\Services\ApprovalGuard::class)->assertDifferent($payment);
        try { app(SupplierPaymentService::class)->approve($payment); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('supplier_payment.approved', $payment, ['status' => 'pending'], ['status' => 'approved']);
        return response()->json(['data' => $payment->fresh()->load('supplier', 'purchaseInvoice'), 'status' => 'approved']);
    }

    public function rejectPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $payment = $this->companyScope(SupplierPayment::query())->findOrFail($id);
        if ($payment->status !== 'pending') throw new \RuntimeException('Only pending supplier payments can be rejected.');
        app(\App\Services\ApprovalGuard::class)->assertDifferent($payment);
        $before = $payment->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $payment->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
        app(AuditService::class)->record('supplier_payment.rejected', $payment, $before, $payment->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return response()->json(['data' => $payment->fresh()->load('supplier', 'purchaseInvoice'), 'status' => 'rejected']);
    }

    public function allocate(Request $request, int $id): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $ownedInvoice = Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['purchase_invoice_id' => ['required', 'integer', $ownedInvoice], 'amount' => ['required', 'numeric', 'gt:0'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'external_reference' => ['nullable', 'string', 'max:150']]);
        if (!empty($data['external_reference']) && ($existing = $this->companyScope(SupplierPaymentAllocation::query())->where('external_reference', $data['external_reference'])->with('invoice')->first())) {
            return response()->json(['data' => $existing, 'idempotent' => true]);
        }
        try {
            $allocation = app(SupplierPaymentService::class)->allocate($this->companyScope(SupplierPayment::query())->findOrFail($id), (int) $data['purchase_invoice_id'], (float) $data['amount'], $data['external_reference'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        app(AuditService::class)->record('supplier_payment.allocated', $allocation, null, $allocation->toArray());
        return response()->json(['data' => $allocation->load('invoice'), 'idempotent' => false], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->companyScope(SupplierPayment::with(['supplier', 'purchaseInvoice', 'allocations.invoice']))->findOrFail($id)]);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);
        try { $allocation = app(SupplierPaymentService::class)->voidAllocation($id, $data['void_reason']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('supplier_payment_allocation.voided', $allocation, null, ['void_reason' => $data['void_reason'], 'api' => true]);
        return response()->json(['data' => $allocation, 'status' => 'voided']);
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
