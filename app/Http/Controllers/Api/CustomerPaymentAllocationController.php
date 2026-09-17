<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\CustomerPaymentAllocationService;
use App\Services\AutomaticAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerPaymentAllocationController extends Controller
{
    public function storePayment(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $ownedCustomer = Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['customer_id' => ['required', 'integer', $ownedCustomer], 'paid_amount' => ['required', 'numeric', 'gt:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:255'], 'external_reference' => ['nullable', 'string', 'max:150']]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(Payment::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $customer = $this->companyScope(Customer::query())->findOrFail($data['customer_id']);
        $rate = (float) ($data['exchange_rate'] ?? 1);
        $payment = DB::transaction(function () use ($data, $customer, $rate, $companyId): Payment {
            $payment = Payment::create(['company_id' => $companyId, 'customer_id' => $customer->id, 'paid_status' => 'unallocated', 'paid_amount' => $data['paid_amount'], 'due_amount' => 0, 'total_amount' => $data['paid_amount'], 'currency_code' => strtoupper($data['currency_code'] ?? (auth()->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $rate, 'base_amount' => (float) $data['paid_amount'] * $rate, 'reference' => $data['reference'] ?? null, 'external_reference' => $data['external_reference'] ?? null]);
            app(AutomaticAccountingService::class)->postCustomerPayment($payment);
            app(AuditService::class)->record('customer_payment.created', $payment, null, $payment->toArray() + ['journalized' => true]);
            return $payment;
        });
        return response()->json(['data' => $payment, 'status' => 'unallocated'], 201);
    }

    public function allocate(Request $request, int $id): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $ownedInvoice = Rule::exists('invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'allocations' => ['required', 'array', 'min:1'], 'allocations.*.invoice_id' => ['required', 'integer', $ownedInvoice], 'allocations.*.amount' => ['required', 'numeric', 'gt:0'], 'allocations.*.exchange_rate' => ['nullable', 'numeric', 'gt:0']]);
        if (!empty($data['external_reference']) && $this->companyScope(CustomerPaymentAllocation::query())->where('external_reference', $data['external_reference'])->exists()) return response()->json(['data' => $this->companyScope(CustomerPaymentAllocation::query())->where('external_reference', $data['external_reference'])->with(['payment', 'invoice'])->first(), 'status' => 'duplicate_ignored']);
        try { $payment = app(CustomerPaymentAllocationService::class)->allocate($this->companyScope(Payment::query())->findOrFail($id), $data['allocations'], $data['external_reference'] ?? null); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('customer_payment.allocated', $payment, null, ['allocations' => $payment->allocations->map(fn ($allocation): array => ['invoice_id' => $allocation->invoice_id, 'amount' => $allocation->amount, 'payment_amount' => $allocation->payment_amount ?? $allocation->amount, 'exchange_rate' => $allocation->exchange_rate])->values()->all()]);
        return response()->json(['data' => $payment, 'status' => 'allocated']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->companyScope(Payment::with('allocations.invoice'))->findOrFail($id)]);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);
        try { $allocation = app(CustomerPaymentAllocationService::class)->void($id, $data['void_reason']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('customer_payment_allocation.voided', $allocation, null, ['void_reason' => $data['void_reason'], 'api' => true]);
        return response()->json(['data' => $allocation, 'status' => 'voided']);
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
