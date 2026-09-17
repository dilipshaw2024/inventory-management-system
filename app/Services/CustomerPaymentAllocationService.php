<?php

namespace App\Services;

use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class CustomerPaymentAllocationService
{
    public function allocate(Payment $payment, array $allocations, ?string $externalReference = null): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $externalReference): Payment {
            $payment = $this->companyScope(Payment::query(), $payment->company_id ?: auth()->user()?->company_id)->lockForUpdate()->findOrFail($payment->id);
            $companyId = $payment->company_id ?: auth()->user()?->company_id;
            if ($payment->is_reversed) throw new \RuntimeException('Reversed payments cannot be allocated.');
            if ($externalReference && ($existing = $this->companyScope(CustomerPaymentAllocation::query(), $payment->company_id ?: auth()->user()?->company_id)->where('external_reference', $externalReference)->first())) return $payment->load('allocations.invoice');
            if ($payment->invoice_id) throw new \RuntimeException('Invoice-bound payments cannot be reallocated. Create an unallocated payment for multi-invoice settlement.');
            $remaining = (float) $payment->paid_amount - (float) CustomerPaymentAllocation::where('payment_id', $payment->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('is_reversed', false))->selectRaw('COALESCE(SUM(COALESCE(payment_amount, amount)), 0) AS total')->value('total');
            foreach ($allocations as $allocation) {
                $amount = (float) ($allocation['amount'] ?? 0);
                if ($amount <= 0 || $amount > $remaining + 0.000001) throw new \RuntimeException('Allocation amount exceeds the unallocated payment balance.');
                $invoice = $this->companyScope(Invoice::query(), $companyId)->lockForUpdate()->whereKey($allocation['invoice_id'])->where('status', 1)->firstOrFail();
                [$paymentAmount, $rate] = $this->convertedAmounts($payment, $invoice, $amount, $allocation['exchange_rate'] ?? null);
                if ($payment->customer_id && $invoice->customer_id && (int) $payment->customer_id !== (int) $invoice->customer_id) throw new \RuntimeException('Payment and invoice customers do not match.');
                $paid = (float) $this->companyScope(Payment::query(), $companyId)->where('invoice_id', $invoice->id)->where('id', '<>', $payment->id)->where('is_reversed', false)->sum('paid_amount');
                $allocated = (float) $this->companyScope(CustomerPaymentAllocation::query(), $companyId)->where('invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('is_reversed', false))->sum('amount');
                if ($paid + $allocated + $amount > (float) $invoice->total_amount + 0.000001) throw new \RuntimeException('Allocation exceeds the invoice outstanding balance.');
                CustomerPaymentAllocation::create(['company_id' => $payment->company_id ?: $companyId, 'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount, 'payment_amount' => $paymentAmount, 'exchange_rate' => $rate, 'external_reference' => $externalReference, 'allocated_at' => now(), 'created_by' => auth()->id()]);
                $remaining -= $paymentAmount;
            }
            $this->syncAllocationStatus($payment);
            return $payment->load('allocations.invoice');
            });
    }

    public function void(int $id, string $reason): CustomerPaymentAllocation
    {
        return DB::transaction(function () use ($id, $reason): CustomerPaymentAllocation {
            $allocation = $this->companyScope(CustomerPaymentAllocation::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($allocation->voided_at) throw new \RuntimeException('This customer payment allocation is already voided.');
            $payment = $this->companyScope(Payment::query(), $allocation->company_id ?: auth()->user()?->company_id)->lockForUpdate()->findOrFail($allocation->payment_id);
            if ($payment->is_reversed) throw new \RuntimeException('Allocations on reversed payments cannot be changed.');
            $allocation->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason]);
            $this->syncAllocationStatus($payment);
            return $allocation->fresh(['payment', 'invoice']);
        });
    }

    private function syncAllocationStatus(Payment $payment): void
    {
        $allocated = (float) CustomerPaymentAllocation::where('payment_id', $payment->id)->whereNull('voided_at')->selectRaw('COALESCE(SUM(COALESCE(payment_amount, amount)), 0) AS total')->value('total');
        $total = (float) $payment->paid_amount;
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

    private function convertedAmounts(Payment $payment, Invoice $invoice, float $invoiceAmount, $requestedRate): array
    {
        $from = strtoupper(trim((string) $payment->currency_code));
        $to = strtoupper(trim((string) $invoice->currency_code));
        if ($from === '' || $to === '' || $from === $to) return [$invoiceAmount, 1.0];
        $rate = $requestedRate !== null ? (float) $requestedRate : app(CurrencyConversionService::class)->rate($from, $to, now()->toDateString());
        if ($rate <= 0) throw new \RuntimeException('Allocation exchange rate must be greater than zero.');
        return [round($invoiceAmount / $rate, 6), $rate];
    }
}
