<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;

class CustomerReceiptProposalService
{
    public function propose(int $companyId, ?string $asOf = null, ?string $dueBy = null, ?int $customerId = null): array
    {
        $asOfDate = CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();
        $dueByDate = CarbonImmutable::parse($dueBy ?: $asOfDate->toDateString())->startOfDay();
        $scope = fn ($query) => $query->where(fn ($company) => $company->where('company_id', $companyId)->orWhereNull('company_id'));
        $invoices = Invoice::with('customer')->where($scope)->where('status', 1)->whereNotNull('customer_id')
            ->where(function ($query) use ($asOfDate): void {
                $query->whereDate('date', '<=', $asOfDate->toDateString())->orWhere(fn ($fallback) => $fallback->whereNull('date')->whereDate('created_at', '<=', $asOfDate->toDateString()));
            })->when($customerId, fn ($query) => $query->where('customer_id', $customerId))->get();
        $invoiceIds = $invoices->pluck('id');
        if ($invoiceIds->isEmpty()) return ['rows' => collect(), 'batches' => collect()];

        $paid = Payment::where($scope)->whereIn('invoice_id', $invoiceIds)->where('approval_status', 'approved')->where('is_reversed', false)
            ->where(fn ($query) => $query->whereDate('payment_date', '<=', $asOfDate->toDateString())->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $asOfDate->toDateString())))
            ->selectRaw('invoice_id, COALESCE(SUM(paid_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $allocated = CustomerPaymentAllocation::where($scope)->whereIn('invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOfDate->toDateString())->whereHas('payment', fn ($query) => $query->where('approval_status', 'approved')->where('is_reversed', false))->selectRaw('invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $credits = CustomerCreditNote::where($scope)->whereIn('invoice_id', $invoiceIds)->where('status', 'approved')->whereDate('credit_date', '<=', $asOfDate->toDateString())->selectRaw('invoice_id, COALESCE(SUM(total_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');

        $rows = $invoices->map(function (Invoice $invoice) use ($paid, $allocated, $credits, $dueByDate): ?array {
            $dueDate = $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->startOfDay() : CarbonImmutable::parse($invoice->date ?: $invoice->created_at)->startOfDay()->addDays((int) ($invoice->customer?->credit_days ?? 0));
            if ($dueDate->gt($dueByDate)) return null;
            $gross = (float) $invoice->total_amount;
            $payments = (float) ($paid[$invoice->id] ?? 0) + (float) ($allocated[$invoice->id] ?? 0);
            $credit = min(max(0, $gross - $payments), (float) ($credits[$invoice->id] ?? 0));
            $outstanding = max(0, $gross - $payments - $credit);
            if ($outstanding <= 0.000001) return null;
            return ['customer_id' => $invoice->customer_id, 'customer' => $invoice->customer, 'invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no, 'invoice_date' => optional($invoice->date ?: $invoice->created_at)->toDateString(), 'due_date' => $dueDate->toDateString(), 'days_overdue' => max(0, $dueDate->diffInDays($dueByDate, false)), 'currency_code' => strtoupper((string) ($invoice->currency_code ?: 'USD')), 'invoice_total' => round($gross, 6), 'paid_amount' => round($payments, 6), 'credit_amount' => round($credit, 6), 'proposed_amount' => round($outstanding, 6)];
        })->filter()->sortBy([['due_date', 'asc'], ['invoice_id', 'asc']])->values();
        $batches = $rows->groupBy(fn (array $row): string => $row['customer_id'].'|'.$row['currency_code'])->map(function ($batch): array {
            return ['customer_id' => $batch->first()['customer_id'], 'customer' => $batch->first()['customer'], 'currency_code' => $batch->first()['currency_code'], 'invoice_count' => $batch->count(), 'proposed_amount' => round((float) $batch->sum('proposed_amount'), 6), 'invoice_ids' => $batch->pluck('invoice_id')->values()->all()];
        })->values();
        return ['rows' => $rows, 'batches' => $batches];
    }
}
