<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\SupplierClaim;
use App\Models\SupplierCreditNote;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Carbon\CarbonImmutable;

class SupplierPaymentProposalService
{
    public function propose(int $companyId, ?string $asOf = null, ?string $dueBy = null, ?int $supplierId = null): array
    {
        $asOfDate = CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();
        $dueByDate = CarbonImmutable::parse($dueBy ?: $asOfDate->toDateString())->startOfDay();
        $scope = fn ($query) => $query->where(fn ($company) => $company->where('company_id', $companyId)->orWhereNull('company_id'));
        $invoices = PurchaseInvoice::with('supplier')->where($scope)->where('status', 'approved')
            ->where(function ($query) use ($asOfDate): void {
                $query->whereDate('invoice_date', '<=', $asOfDate->toDateString())
                    ->orWhere(fn ($fallback) => $fallback->whereNull('invoice_date')->whereDate('created_at', '<=', $asOfDate->toDateString()));
            })->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))->get();
        $invoiceIds = $invoices->pluck('id');
        if ($invoiceIds->isEmpty()) return ['rows' => collect(), 'batches' => collect(), 'unallocated_credits' => 0.0];

        $paid = SupplierPayment::where($scope)->whereIn('purchase_invoice_id', $invoiceIds)->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $asOfDate->toDateString())->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $allocated = SupplierPaymentAllocation::where($scope)->whereIn('purchase_invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOfDate->toDateString())->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $claimCredits = SupplierClaim::where($scope)->whereIn('purchase_invoice_id', $invoiceIds)->whereIn('status', ['partially_settled', 'settled'])->where('settled_amount', '>', 0)->whereDate('settled_at', '<=', $asOfDate->toDateString())->selectRaw('purchase_invoice_id, COALESCE(SUM(settled_amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $noteCredits = SupplierCreditNote::where($scope)->whereIn('purchase_invoice_id', $invoiceIds)->whereNull('supplier_claim_id')->where('status', 'approved')->whereDate('approved_at', '<=', $asOfDate->toDateString())->selectRaw('purchase_invoice_id, COALESCE(SUM(total_amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $unallocatedCredits = (float) SupplierClaim::where($scope)->whereNull('purchase_invoice_id')->whereIn('status', ['partially_settled', 'settled'])->where('settled_amount', '>', 0)->whereDate('settled_at', '<=', $asOfDate->toDateString())->sum('settled_amount')
            + (float) SupplierCreditNote::where($scope)->whereNull('purchase_invoice_id')->whereNull('supplier_claim_id')->where('status', 'approved')->whereDate('approved_at', '<=', $asOfDate->toDateString())->sum('total_amount');

        $rows = $invoices->map(function (PurchaseInvoice $invoice) use ($paid, $allocated, $claimCredits, $noteCredits, $dueByDate): ?array {
            $dueDate = app(SupplierPayablesService::class)->dueDate($invoice);
            if ($dueDate->gt($dueByDate)) return null;
            $gross = (float) $invoice->total_amount;
            $payments = (float) ($paid[$invoice->id] ?? 0) + (float) ($allocated[$invoice->id] ?? 0);
            $credits = min(max(0, $gross - $payments), (float) ($claimCredits[$invoice->id] ?? 0) + (float) ($noteCredits[$invoice->id] ?? 0));
            $outstanding = max(0, $gross - $payments - $credits);
            if ($outstanding <= 0.000001) return null;
            $daysOverdue = max(0, $dueDate->diffInDays($dueByDate, false));
            return [
                'supplier_id' => $invoice->supplier_id,
                'supplier' => $invoice->supplier,
                'purchase_invoice_id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'invoice_date' => optional($invoice->invoice_date ?: $invoice->created_at)->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'days_overdue' => $daysOverdue,
                'currency_code' => strtoupper((string) ($invoice->currency_code ?: 'USD')),
                'invoice_total' => round($gross, 6),
                'paid_amount' => round($payments, 6),
                'credit_amount' => round($credits, 6),
                'proposed_amount' => round($outstanding, 6),
            ];
        })->filter()->sortBy([['due_date', 'asc'], ['purchase_invoice_id', 'asc']])->values();
        $batches = $rows->groupBy(fn (array $row): string => $row['supplier_id'].'|'.$row['currency_code'])->map(function ($batch): array {
            return ['supplier_id' => $batch->first()['supplier_id'], 'supplier' => $batch->first()['supplier'], 'currency_code' => $batch->first()['currency_code'], 'invoice_count' => $batch->count(), 'proposed_amount' => round((float) $batch->sum('proposed_amount'), 6), 'invoice_ids' => $batch->pluck('purchase_invoice_id')->values()->all()];
        })->values();
        return ['rows' => $rows, 'batches' => $batches, 'unallocated_credits' => round($unallocatedCredits, 6)];
    }
}
