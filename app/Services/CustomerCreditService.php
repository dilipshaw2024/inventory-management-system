<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;

class CustomerCreditService
{
    public function assess(Customer $customer, ?string $asOf = null): array
    {
        $asOfDate = CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();
        $invoices = Invoice::where('customer_id', $customer->id)
            ->where('status', 1)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereDate('date', '<=', $asOfDate->toDateString())
                    ->orWhere(function ($fallback) use ($asOfDate): void {
                        $fallback->whereNull('date')->whereDate('created_at', '<=', $asOfDate->toDateString());
                    });
            })->get(['id', 'date', 'created_at', 'total_amount']);

        $invoiceIds = $invoices->pluck('id');
        $paid = $invoiceIds->isEmpty() ? collect() : Payment::whereIn('invoice_id', $invoiceIds)->where('approval_status', 'approved')
            ->where('is_reversed', false)->where(fn ($query) => $query->whereDate('payment_date', '<=', $asOfDate->toDateString())->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $asOfDate->toDateString())))
            ->selectRaw('invoice_id, COALESCE(SUM(paid_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $allocated = $invoiceIds->isEmpty() ? collect() : CustomerPaymentAllocation::whereIn('invoice_id', $invoiceIds)
            ->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOfDate->toDateString())
            ->whereHas('payment', fn ($query) => $query->where('approval_status', 'approved')->where('is_reversed', false))
            ->selectRaw('invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $credits = $invoiceIds->isEmpty() ? collect() : CustomerCreditNote::whereIn('invoice_id', $invoiceIds)
            ->where('status', 'approved')->whereDate('credit_date', '<=', $asOfDate->toDateString())
            ->selectRaw('invoice_id, COALESCE(SUM(total_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');

        $outstanding = 0.0;
        $overdueAmount = 0.0;
        $oldestOverdueDays = 0;
        foreach ($invoices as $invoice) {
            $balance = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0) - (float) ($credits[$invoice->id] ?? 0));
            if ($balance <= 0.000001) continue;
            $outstanding += $balance;
            $baseDate = $invoice->date ?: $invoice->created_at;
            $dueDate = CarbonImmutable::parse($baseDate)->startOfDay()->addDays((int) $customer->credit_days);
            $daysOverdue = max(0, $dueDate->diffInDays($asOfDate, false));
            if ($daysOverdue > 0) {
                $overdueAmount += $balance;
                $oldestOverdueDays = max($oldestOverdueDays, $daysOverdue);
            }
        }

        $legacyInvoiceIds = $invoiceIds->all() ?: [-1];
        $legacy = Payment::where('customer_id', $customer->id)->where('approval_status', 'approved')->whereNotIn('invoice_id', $legacyInvoiceIds)
            ->where('due_amount', '>', 0)->where('is_reversed', false)->where(fn ($query) => $query->whereDate('payment_date', '<=', $asOfDate->toDateString())->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $asOfDate->toDateString())))->get(['due_amount', 'payment_date', 'created_at']);
        foreach ($legacy as $payment) {
            $balance = (float) $payment->due_amount;
            $outstanding += $balance;
            $dueDate = CarbonImmutable::parse($payment->payment_date ?: $payment->created_at)->startOfDay()->addDays((int) $customer->credit_days);
            $daysOverdue = max(0, $dueDate->diffInDays($asOfDate, false));
            if ($daysOverdue > 0) {
                $overdueAmount += $balance;
                $oldestOverdueDays = max($oldestOverdueDays, $daysOverdue);
            }
        }

        return [
            'outstanding' => round($outstanding, 6),
            'overdue_amount' => round($overdueAmount, 6),
            'oldest_overdue_days' => $oldestOverdueDays,
            'collection_status' => $this->collectionStatus($customer, $overdueAmount, $oldestOverdueDays),
        ];
    }

    public function collectionStatus(Customer $customer, float $overdueAmount, int $oldestOverdueDays): string
    {
        if ($overdueAmount <= 0.000001) return 'current';
        if ($customer->credit_hold) return 'on_hold';
        return $oldestOverdueDays >= 90 ? 'escalated' : 'overdue';
    }

    public function assertCanApprove(Customer $customer, float $orderValue): void
    {
        if ($customer->credit_hold) throw new \RuntimeException('Customer is on credit hold.');
        $assessment = $this->assess($customer);
        $threshold = (int) ($customer->credit_hold_after_days ?? 0);
        if ($threshold > 0 && $assessment['overdue_amount'] > 0.000001 && $assessment['oldest_overdue_days'] >= $threshold) {
            throw new \RuntimeException('Customer has overdue credit beyond the configured '.$threshold.'-day limit.');
        }
        if ((float) $customer->credit_limit > 0 && $assessment['outstanding'] + $orderValue > (float) $customer->credit_limit + 0.000001) {
            throw new \RuntimeException('Customer credit limit would be exceeded.');
        }
    }
}
