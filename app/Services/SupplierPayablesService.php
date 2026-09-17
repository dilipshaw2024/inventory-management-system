<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierClaim;
use App\Models\SupplierCreditNote;
use Carbon\CarbonImmutable;

class SupplierPayablesService
{
    public function assess(Supplier $supplier, ?string $asOf = null): array
    {
        $asOfDate = CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();
        $invoices = PurchaseInvoice::with('supplier')->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($asOfDate): void {
                $query->whereDate('invoice_date', '<=', $asOfDate->toDateString())
                    ->orWhere(function ($fallback) use ($asOfDate): void {
                        $fallback->whereNull('invoice_date')->whereDate('created_at', '<=', $asOfDate->toDateString());
                    });
            })->get(['id', 'invoice_date', 'due_date', 'created_at', 'total_amount']);
        $invoiceIds = $invoices->pluck('id');
        $paid = $invoiceIds->isEmpty() ? collect() : SupplierPayment::whereIn('purchase_invoice_id', $invoiceIds)
            ->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $asOfDate->toDateString())
            ->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $claimQuery = SupplierClaim::where('supplier_id', $supplier->id)
            ->whereIn('status', ['partially_settled', 'settled'])
            ->where('settled_amount', '>', 0)
            ->whereDate('settled_at', '<=', $asOfDate->toDateString());
        $claimInvoiceCredits = (clone $claimQuery)->whereNotNull('purchase_invoice_id')->selectRaw('purchase_invoice_id, COALESCE(SUM(settled_amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $unallocatedClaims = (float) (clone $claimQuery)->whereNull('purchase_invoice_id')->sum('settled_amount');
        $creditNoteQuery = SupplierCreditNote::where('supplier_id', $supplier->id)->where('status', 'approved')->where('total_amount', '>', 0)->whereDate('approved_at', '<=', $asOfDate->toDateString());
        $noteInvoiceCredits = (clone $creditNoteQuery)->whereNull('supplier_claim_id')->whereNotNull('purchase_invoice_id')->selectRaw('purchase_invoice_id, COALESCE(SUM(total_amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $unallocatedNotes = (float) (clone $creditNoteQuery)->whereNull('supplier_claim_id')->whereNull('purchase_invoice_id')->sum('total_amount');
        $unallocatedClaims += $unallocatedNotes;
        $allocated = $invoiceIds->isEmpty() ? collect() : SupplierPaymentAllocation::whereIn('purchase_invoice_id', $invoiceIds)
            ->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOfDate->toDateString())
            ->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))
            ->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');

        $outstanding = 0.0;
        $overdueAmount = 0.0;
        $oldestOverdueDays = 0;
        foreach ($invoices->sortBy(fn (PurchaseInvoice $invoice) => ($invoice->invoice_date ?: $invoice->created_at)->toDateString()) as $invoice) {
            $balance = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0));
            $credit = min($balance, (float) ($claimInvoiceCredits[$invoice->id] ?? 0) + (float) ($noteInvoiceCredits[$invoice->id] ?? 0));
            $balance -= $credit;
            if ($unallocatedClaims > 0) {
                $credit = min($balance, $unallocatedClaims);
                $balance -= $credit;
                $unallocatedClaims -= $credit;
            }
            if ($balance <= 0.000001) continue;
            $outstanding += $balance;
            $dueDate = $this->dueDate($invoice);
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
            'collection_status' => $this->collectionStatus($overdueAmount, $oldestOverdueDays),
        ];
    }

    public function dueDate(PurchaseInvoice $invoice): CarbonImmutable
    {
        return $this->dueDateFromValues(
            $invoice->getRawOriginal('due_date'),
            $invoice->getRawOriginal('invoice_date'),
            $invoice->getRawOriginal('created_at'),
            (int) ($invoice->supplier?->payment_terms_days ?? 0)
        );
    }

    public function dueDateFromValues(?string $dueDate, ?string $invoiceDate, mixed $createdAt, int $paymentTermsDays): CarbonImmutable
    {
        if ($dueDate) return CarbonImmutable::parse($dueDate)->startOfDay();
        return CarbonImmutable::parse($invoiceDate ?: $createdAt)->startOfDay()->addDays($paymentTermsDays);
    }

    public function collectionStatus(float $overdueAmount, int $oldestOverdueDays): string
    {
        if ($overdueAmount <= 0.000001) return 'current';
        return $oldestOverdueDays >= 90 ? 'escalated' : 'overdue';
    }
}
