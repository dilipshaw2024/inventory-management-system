<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function approve(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $companyId = $payment->company_id ?: auth()->user()?->company_id;
            $invoice = $payment->purchase_invoice_id ? $this->companyScope(PurchaseInvoice::query(), $companyId)->lockForUpdate()->findOrFail($payment->purchase_invoice_id) : null;
            if ($invoice && $invoice->status !== 'approved') throw new \RuntimeException('Only approved purchase invoices can be paid.');
            if ($invoice) $this->assertCurrencyCompatible($payment->currency_code, $invoice->currency_code);
            if ($invoice) {
                $paid = (float) SupplierPayment::where('purchase_invoice_id', $invoice->id)->where('status', 'approved')->where('is_reversed', false)->sum('amount');
                $allocated = (float) SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->sum('amount');
                if ($paid + $allocated + (float) $payment->amount > (float) $invoice->total_amount + 0.000001) throw new \RuntimeException('Payment exceeds the outstanding invoice balance.');
            }
            $currency = $companyId ? (\App\Models\Company::whereKey($companyId)->value('base_currency') ?: 'USD') : 'USD';
            $baseAmount = (float) ($payment->base_amount ?: ((float) $payment->amount * (float) ($payment->exchange_rate ?: 1)));
            $ap = $this->account('accounts_payable', $companyId);
            $cash = $this->account($payment->method === 'cash' ? 'cash' : 'bank', $companyId) ?? $this->account('cash_bank', $companyId);
            if ($ap && $cash) app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => $payment->payment_date->toDateString(), 'description' => 'Supplier payment '.$payment->payment_no], [['account_id' => $ap, 'debit' => $baseAmount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1], ['account_id' => $cash, 'debit' => 0, 'credit' => $baseAmount, 'currency_code' => $currency, 'exchange_rate' => 1]], $payment);
            $payment->update(['base_amount' => $baseAmount, 'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    public function allocate(SupplierPayment $payment, int $invoiceId, float $amount, ?string $externalReference = null, ?float $requestedRate = null): SupplierPaymentAllocation
    {
        return DB::transaction(function () use ($payment, $invoiceId, $amount, $externalReference, $requestedRate): SupplierPaymentAllocation {
            $payment = $this->companyScope(SupplierPayment::query(), $payment->company_id ?: auth()->user()?->company_id)->lockForUpdate()->findOrFail($payment->id);
            if ($externalReference && ($existing = $this->companyScope(SupplierPaymentAllocation::query(), $payment->company_id ?: auth()->user()?->company_id)->where('external_reference', $externalReference)->first())) return $existing;
            if ($payment->status !== 'approved' || $payment->purchase_invoice_id) throw new \RuntimeException('Only approved unallocated supplier payments can be allocated.');
            $companyId = $payment->company_id ?: auth()->user()?->company_id;
            $invoice = $this->companyScope(PurchaseInvoice::query(), $companyId)->lockForUpdate()->whereKey($invoiceId)->where('status', 'approved')->firstOrFail();
            if ((int) $invoice->supplier_id !== (int) $payment->supplier_id) throw new \RuntimeException('Payment and invoice suppliers do not match.');
            $rate = $this->allocationRate($payment, $invoice, $requestedRate);
            $paymentAmount = round($amount / $rate, 6);
            $allocated = (float) SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->selectRaw('COALESCE(SUM(COALESCE(payment_amount, amount)), 0) AS total')->value('total');
            $paid = (float) SupplierPayment::where('purchase_invoice_id', $invoice->id)->where('status', 'approved')->where('is_reversed', false)->sum('amount');
            $invoiceAllocated = (float) SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->sum('amount');
            if ($amount <= 0 || $allocated + $paymentAmount > (float) $payment->amount + 0.000001) throw new \RuntimeException('Allocation exceeds the unallocated payment balance.');
            if ($paid + $invoiceAllocated + $amount > (float) $invoice->total_amount + 0.000001) throw new \RuntimeException('Allocation exceeds the invoice outstanding balance.');
            $allocation = SupplierPaymentAllocation::create(['company_id' => $payment->company_id, 'supplier_payment_id' => $payment->id, 'purchase_invoice_id' => $invoice->id, 'amount' => $amount, 'payment_amount' => $paymentAmount, 'exchange_rate' => $rate, 'external_reference' => $externalReference, 'allocated_at' => now(), 'created_by' => auth()->id()]);
            $this->syncAllocationStatus($payment);
            return $allocation;
        });
    }
    private function account(string $key, ?int $companyId): ?int { return AccountMapping::where('mapping_key', $key)->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->orderByRaw('company_id IS NULL')->value('account_id'); }

    public function voidAllocation(int $id, string $reason): SupplierPaymentAllocation
    {
        return DB::transaction(function () use ($id, $reason): SupplierPaymentAllocation {
            $allocation = $this->companyScope(SupplierPaymentAllocation::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($allocation->voided_at) throw new \RuntimeException('This supplier payment allocation is already voided.');
            $payment = $this->companyScope(SupplierPayment::query(), $allocation->company_id ?: auth()->user()?->company_id)->lockForUpdate()->findOrFail($allocation->supplier_payment_id);
            if ($payment->is_reversed) throw new \RuntimeException('Allocations on reversed payments cannot be changed.');
            $allocation->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason]);
            $this->syncAllocationStatus($payment);
            return $allocation->fresh(['payment', 'invoice']);
        });
    }

    private function syncAllocationStatus(SupplierPayment $payment): void
    {
        $allocated = (float) SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->whereNull('voided_at')->selectRaw('COALESCE(SUM(COALESCE(payment_amount, amount)), 0) AS total')->value('total');
        $total = (float) $payment->amount;
        $payment->update(['allocation_status' => $allocated <= 0.000001 ? 'unallocated' : ($allocated + 0.000001 >= $total ? 'fully_allocated' : 'partially_allocated')]);
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    public function assertCurrencyCompatible(?string $paymentCurrency, ?string $invoiceCurrency): void
    {
        $paymentCurrency = strtoupper(trim((string) $paymentCurrency));
        $invoiceCurrency = strtoupper(trim((string) $invoiceCurrency));
        if ($paymentCurrency !== '' && $invoiceCurrency !== '' && $paymentCurrency !== $invoiceCurrency) {
            throw new \RuntimeException('Payment and invoice currencies must match before allocation.');
        }
    }

    private function allocationRate(SupplierPayment $payment, PurchaseInvoice $invoice, ?float $requestedRate): float
    {
        $from = strtoupper(trim((string) $payment->currency_code));
        $to = strtoupper(trim((string) $invoice->currency_code));
        if ($from === '' || $to === '' || $from === $to) return 1.0;
        $rate = $requestedRate ?? app(CurrencyConversionService::class)->rate($from, $to, now()->toDateString());
        if ($rate <= 0) throw new \RuntimeException('Allocation exchange rate must be greater than zero.');
        return $rate;
    }
}
