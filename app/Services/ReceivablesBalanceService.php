<?php

namespace App\Services;

use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\Payment;

class ReceivablesBalanceService
{
    public function outstandingForCustomer(int $customerId): float
    {
        $invoiceIds = Invoice::where('customer_id', $customerId)->pluck('id');
        $invoiceBalance = (float) Invoice::whereIn('id', $invoiceIds)->where('status', 1)->sum('total_amount')
            - (float) Payment::whereIn('invoice_id', $invoiceIds)->where('approval_status', 'approved')->sum('paid_amount')
            - (float) CustomerPaymentAllocation::whereIn('invoice_id', $invoiceIds)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('approval_status', 'approved')->where('is_reversed', false))->sum('amount');
        $legacyBalance = (float) Payment::where('customer_id', $customerId)->where('approval_status', 'approved')->whereNotIn('invoice_id', $invoiceIds->isEmpty() ? [-1] : $invoiceIds)->sum('due_amount');
        return max(0, $invoiceBalance + $legacyBalance);
    }
}
