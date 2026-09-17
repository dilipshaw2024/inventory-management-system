<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\TaxSettlement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaxSettlementService
{
    public function settle(int $companyId, string $from, string $to, ?string $jurisdiction, float $netTax, string $paidAt, ?string $reference = null): TaxSettlement
    {
        $externalReference = 'tax-settlement:'.$companyId.':'.$from.':'.$to.':'.($jurisdiction ?: 'all');
        $existing = TaxSettlement::where('company_id', $companyId)->where('external_reference', $externalReference)->first();
        if ($existing) return $existing->fresh('journalEntry');
        $netTax = round($netTax, 6);
        if ($netTax <= 0) throw new RuntimeException('Only a positive net tax position can be settled.');
        $taxPayable = $this->account('tax_payable', $companyId) ?: $this->account('sales_tax', $companyId);
        $cash = $this->account('cash_bank', $companyId) ?: $this->account('bank', $companyId) ?: $this->account('cash', $companyId);
        if (!$taxPayable || !$cash) throw new RuntimeException('Configure tax_payable and cash, bank, or cash_bank account mappings before tax settlement.');
        return DB::transaction(function () use ($companyId, $from, $to, $jurisdiction, $netTax, $paidAt, $reference, $externalReference, $taxPayable, $cash): TaxSettlement {
            $settlement = TaxSettlement::create([
                'company_id' => $companyId, 'settlement_no' => 'TAX-SET-'.$from.'-'.$to.($jurisdiction ? '-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $jurisdiction), 0, 12)) : ''),
                'external_reference' => $externalReference, 'period_from' => $from, 'period_to' => $to, 'jurisdiction' => $jurisdiction,
                'net_tax' => $netTax, 'paid_at' => $paidAt, 'payment_reference' => $reference, 'created_by' => auth()->id(),
            ]);
            $journal = app(AccountingService::class)->post([
                'company_id' => $companyId, 'entry_no' => 'TAX-SET-'.$settlement->id, 'external_reference' => $externalReference,
                'date' => $paidAt, 'description' => 'Tax settlement '.$from.' to '.$to.($jurisdiction ? ' ('.$jurisdiction.')' : ''),
            ], [
                ['account_id' => $taxPayable, 'debit' => $netTax, 'credit' => 0, 'description' => 'Tax remittance'],
                ['account_id' => $cash, 'debit' => 0, 'credit' => $netTax, 'description' => 'Tax payment'],
            ], $settlement);
            $settlement->update(['journal_entry_id' => $journal->id]);
            return $settlement->fresh(['journalEntry', 'creator']);
        });
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
