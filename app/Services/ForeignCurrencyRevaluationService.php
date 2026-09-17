<?php

namespace App\Services;

use App\Models\CustomerPaymentAllocation;
use App\Models\Company;
use App\Models\AccountMapping;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Support\Collection;

class ForeignCurrencyRevaluationService
{
    public function openBalances(string $asOf, ?int $companyId = null): Collection
    {
        $companyId ??= auth()->user()?->company_id;
        $base = strtoupper(($companyId ? Company::find($companyId)?->base_currency : auth()->user()?->company?->base_currency) ?? 'USD');
        $rows = collect();
        Invoice::where('status', 1)->whereDate('date', '<=', $asOf)->whereNotNull('currency_code')->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->each(function (Invoice $invoice) use ($asOf, $base, $rows): void {
            $this->append($rows, 'Accounts receivable', $invoice->invoice_no, $invoice->currency_code, (float) $invoice->total_amount - (float) Payment::where('invoice_id', $invoice->id)->where('is_reversed', false)->whereDate('created_at', '<=', $asOf)->sum('paid_amount') - (float) CustomerPaymentAllocation::where('invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('is_reversed', false))->whereDate('allocated_at', '<=', $asOf)->sum('amount'), (float) ($invoice->exchange_rate ?: 1), $asOf, $base, $invoice->id);
        });
        PurchaseInvoice::where('status', 'approved')->whereDate('invoice_date', '<=', $asOf)->whereNotNull('currency_code')->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->each(function (PurchaseInvoice $invoice) use ($asOf, $base, $rows): void {
            $this->append($rows, 'Accounts payable', $invoice->invoice_no, $invoice->currency_code, (float) $invoice->total_amount - (float) SupplierPayment::where('purchase_invoice_id', $invoice->id)->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $asOf)->sum('amount') - (float) SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->whereDate('allocated_at', '<=', $asOf)->sum('amount'), (float) ($invoice->exchange_rate ?: 1), $asOf, $base, $invoice->id);
        });
        return $rows->filter(fn (array $row): bool => abs($row['difference']) >= 0.005)->values();
    }

    public function post(string $asOf): JournalEntry
    {
        $companyId = auth()->user()?->company_id;
        if (!$companyId) throw new \RuntimeException('A company context is required for foreign-currency revaluation.');
        $reference = 'FX-REVAL-'.$companyId.'-'.$asOf;
        $existing = JournalEntry::where('company_id', $companyId)->where('external_reference', $reference)->first();
        if ($existing) return $existing->load('lines.account');

        $rows = $this->openBalances($asOf, $companyId);
        if ($rows->isEmpty()) throw new \RuntimeException('There is no foreign-currency variance to post for this date.');
        $accounts = [
            'accounts_receivable' => $this->account('accounts_receivable', $companyId),
            'accounts_payable' => $this->account('accounts_payable', $companyId),
            'fx_gain' => $this->account('fx_gain', $companyId),
            'fx_loss' => $this->account('fx_loss', $companyId),
        ];
        if (!$accounts['accounts_receivable'] || !$accounts['accounts_payable'] || !$accounts['fx_gain'] || !$accounts['fx_loss']) {
            throw new \RuntimeException('Configure accounts receivable, accounts payable, FX gain, and FX loss mappings before posting.');
        }

        $totals = collect();
        foreach ($rows as $row) {
            $difference = round((float) $row['difference'], 6);
            $control = $row['type'] === 'Accounts receivable' ? $accounts['accounts_receivable'] : $accounts['accounts_payable'];
            if ($difference > 0) {
                $this->add($totals, $control, 'debit', $difference);
                $this->add($totals, $accounts['fx_gain'], 'credit', $difference);
            } else {
                $this->add($totals, $accounts['fx_loss'], 'debit', abs($difference));
                $this->add($totals, $control, 'credit', abs($difference));
            }
        }
        $base = strtoupper(auth()->user()?->company?->base_currency ?? 'USD');
        $lines = $totals->flatMap(function (array $amounts, int $accountId) use ($base): Collection {
            $lines = collect();
            if ($amounts['debit'] > 0) $lines->push(['account_id' => $accountId, 'debit' => round($amounts['debit'], 6), 'credit' => 0, 'currency_code' => $base, 'exchange_rate' => 1, 'description' => 'Foreign-currency revaluation']);
            if ($amounts['credit'] > 0) $lines->push(['account_id' => $accountId, 'debit' => 0, 'credit' => round($amounts['credit'], 6), 'currency_code' => $base, 'exchange_rate' => 1, 'description' => 'Foreign-currency revaluation']);
            return $lines;
        })->values()->all();

        return app(\App\Services\AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'FX-'.$companyId.'-'.$asOf,
            'external_reference' => $reference,
            'date' => $asOf,
            'description' => 'Foreign-currency revaluation as of '.$asOf,
        ], $lines);
    }

    private function add(Collection $totals, int $accountId, string $side, float $amount): void
    {
        $current = $totals->get($accountId, ['debit' => 0.0, 'credit' => 0.0]);
        $current[$side] += $amount;
        $totals->put($accountId, $current);
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(function ($query) use ($companyId): void {
            $query->where('company_id', $companyId)->orWhereNull('company_id');
        })->orderByRaw('company_id IS NULL')->value('account_id');
    }

    private function append(Collection $rows, string $type, string $document, string $currency, float $outstanding, float $bookedRate, string $asOf, string $base, int $documentId): void
    {
        if ($outstanding <= 0.000001 || strtoupper($currency) === $base) return;
        try { $currentRate = app(CurrencyConversionService::class)->rate($currency, $base, $asOf); }
        catch (\RuntimeException) { return; }
        $booked = $outstanding * $bookedRate; $current = $outstanding * $currentRate;
        $rows->push(['type' => $type, 'document' => $document, 'document_id' => $documentId, 'currency' => strtoupper($currency), 'outstanding' => $outstanding, 'booked_rate' => $bookedRate, 'current_rate' => $currentRate, 'booked_base' => $booked, 'current_base' => $current, 'difference' => $current - $booked]);
    }
}
