<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerRefund;
use App\Models\InventoryReturn;
use App\Services\AuditService;
use App\Services\CustomerRefundService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerRefundController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'inventory_return_id' => ['required', 'integer', $owned('inventory_returns')], 'customer_id' => ['required', 'integer', $owned('customers')], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'in:cash,bank,card,transfer,other'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000']]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(CustomerRefund::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored', 'idempotent' => true]);
        }
        $return = $this->companyScope(InventoryReturn::query())->findOrFail($data['inventory_return_id']);
        if ($return->status !== 'approved' || $return->return_type !== 'sales' || (int) $return->customer_id !== (int) $data['customer_id']) return response()->json(['message' => 'Refund must reference an approved sales return for the same customer.'], 422);
        $rate = (float) ($data['exchange_rate'] ?? 1); $refund = CustomerRefund::create($data + ['refund_no' => app(NumberingSequenceService::class)->nextOrFallback('customer_refund', 'REF-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'company_id' => auth()->user()?->company_id, 'created_by' => auth()->id(), 'currency_code' => strtoupper($data['currency_code'] ?? (auth()->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $rate, 'base_amount' => (float) $data['amount'] * $rate]);
        app(AuditService::class)->record('customer_refund.created', $refund, null, $refund->toArray());
        return response()->json(['data' => $refund, 'status' => 'pending_approval'], 201);
    }

    public function approve(int $id): JsonResponse
    {
        try { app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(CustomerRefund::class, $id); $refund = $this->companyScope(CustomerRefund::query())->findOrFail($id); app(\App\Services\ApprovalGuard::class)->assertDifferent($refund); $approved = app(CustomerRefundService::class)->approve($refund); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('customer_refund.approved', $approved, ['status' => 'pending'], ['status' => 'approved']);
        return response()->json(['data' => $approved, 'status' => 'approved']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $refund = $this->companyScope(CustomerRefund::query())->findOrFail($id);
            if ($refund->status !== 'pending') throw new \RuntimeException('Only pending customer refunds can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($refund);
            $before = $refund->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $refund->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('customer_refund.rejected', $refund, $before, $refund->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return response()->json(['data' => $refund->fresh(), 'status' => 'rejected']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
