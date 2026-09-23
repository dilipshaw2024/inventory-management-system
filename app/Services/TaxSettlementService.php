<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\TaxSettlement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaxSettlementService
{
    public function settle(int $companyId, string $from, string $to, ?string $jurisdiction, float $netTax, string $paidAt, ?string $reference = null, ?string $requestedExternalReference = null): TaxSettlement
    {
        $externalReference = trim((string) ($requestedExternalReference ?: ('tax-settlement:'.$companyId.':'.$from.':'.$to.':'.($jurisdiction ?: 'all'))));
        if ($externalReference === '') throw new RuntimeException('A tax settlement external reference is required.');
        $existing = TaxSettlement::where('company_id', $companyId)->where('external_reference', $externalReference)->first();
        if ($existing) {
            if ($existing->status === 'reversed') throw new RuntimeException('This tax settlement reference was reversed; provide a new correction external reference.');
            return $existing->fresh('journalEntry');
        }
        $netTax = round($netTax, 6);
        if ($netTax <= 0) throw new RuntimeException('Only a positive net tax position can be settled.');
        $taxPayable = $this->account('tax_payable', $companyId) ?: $this->account('sales_tax', $companyId);
        $cash = $this->account('cash_bank', $companyId) ?: $this->account('bank', $companyId) ?: $this->account('cash', $companyId);
        if (!$taxPayable || !$cash) throw new RuntimeException('Configure tax_payable and cash, bank, or cash_bank account mappings before tax settlement.');
        return DB::transaction(function () use ($companyId, $from, $to, $jurisdiction, $netTax, $paidAt, $reference, $externalReference, $taxPayable, $cash): TaxSettlement {
            $baseSettlementNo = 'TAX-SET-'.$from.'-'.$to.($jurisdiction ? '-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $jurisdiction), 0, 12)) : '');
            $settlementNo = $baseSettlementNo;
            $suffix = 2;
            while (TaxSettlement::where('company_id', $companyId)->where('settlement_no', $settlementNo)->exists()) {
                $settlementNo = $baseSettlementNo.'-R'.$suffix++;
            }
            $settlement = TaxSettlement::create([
                'company_id' => $companyId, 'settlement_no' => $settlementNo,
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

    public function reverse(TaxSettlement $settlement, string $reason): TaxSettlement
    {
        return DB::transaction(function () use ($settlement, $reason): TaxSettlement {
            $settlement = TaxSettlement::with('journalEntry')->lockForUpdate()->findOrFail($settlement->getKey());
            if ($settlement->reversal_journal_entry_id) {
                throw new \RuntimeException('This tax settlement has already been reversed.');
            }
            if (!$settlement->journalEntry) {
                throw new \RuntimeException('This tax settlement has no journal entry to reverse.');
            }

            $reversal = app(AccountingService::class)->reverse($settlement->journalEntry, $reason);
            $settlement->update([
                'status' => 'reversed',
                'reversal_journal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
            ]);

            return $settlement->fresh(['journalEntry', 'reversalJournalEntry', 'creator', 'reverser']);
        });
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
