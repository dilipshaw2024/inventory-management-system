<?php

namespace App\Http\Controllers;

use App\Models\CustomerRefund;
use App\Models\InventoryReturn;
use App\Services\AuditService;
use App\Services\CustomerRefundService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;

class CustomerRefundController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'inventory_return_id' => ['required', 'integer', 'exists:inventory_returns,id'], 'customer_id' => ['required', 'integer', 'exists:customers,id'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'in:cash,bank,card,transfer,other'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $return = InventoryReturn::findOrFail($data['inventory_return_id']);
        if ($return->status !== 'approved' || $return->return_type !== 'sales' || (int) $return->customer_id !== (int) $data['customer_id']) return back()->with(['message' => 'Refund must reference an approved sales return for the same customer.', 'alert-type' => 'error']);
        $rate = (float) ($data['exchange_rate'] ?? 1); $refund = CustomerRefund::create($data + ['refund_no' => app(NumberingSequenceService::class)->nextOrFallback('customer_refund', 'REF-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'company_id' => auth()->user()?->company_id, 'created_by' => auth()->id(), 'currency_code' => strtoupper($data['currency_code'] ?? (auth()->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $rate, 'base_amount' => (float) $data['amount'] * $rate]);
        app(AuditService::class)->record('customer_refund.created', $refund, null, $refund->toArray());
        return back()->with(['message' => 'Customer refund submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        try {
            app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(CustomerRefund::class, $id);
            $refund = CustomerRefund::findOrFail($id);
            app(\App\Services\ApprovalGuard::class)->assertDifferent($refund);
            $approved = app(CustomerRefundService::class)->approve($refund);
            app(AuditService::class)->record('customer_refund.approved', $approved, ['status' => 'pending'], ['status' => 'approved']);
            return back()->with(['message' => 'Customer refund approved and journalized.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $refund = CustomerRefund::findOrFail($id);
            if ($refund->status !== 'pending') throw new \RuntimeException('Only pending customer refunds can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($refund);
            $before = $refund->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $refund->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('customer_refund.rejected', $refund, $before, $refund->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'Customer refund rejected without posting a refund journal.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }
}
