<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\PosSession;
use App\Models\JournalEntry;

class PosCashVarianceAccountingService
{
    public function post(PosSession $session): ?JournalEntry
    {
        $variance = (float) $session->variance;
        if (abs($variance) <= 0.000001) {
            $session->update(['variance_accounting_status' => 'not_required', 'variance_accounting_message' => null]);
            return null;
        }
        if ($session->variance_journal_id) {
            return $session->varianceJournal;
        }

        $companyId = (int) $session->company_id;
        $cash = $this->account('cash', $companyId) ?: $this->account('cash_bank', $companyId);
        $varianceAccount = $this->account('cash_over_short', $companyId);
        if (!$cash || !$varianceAccount) {
            $session->update([
                'variance_accounting_status' => 'unmapped',
                'variance_accounting_message' => 'Configure cash and cash_over_short account mappings before posting POS variance.',
            ]);
            return null;
        }

        $amount = abs($variance);
        $currency = Company::whereKey($companyId)->value('base_currency') ?: 'USD';
        $lines = $variance > 0
            ? [
                ['account_id' => $cash, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
                ['account_id' => $varianceAccount, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1],
            ]
            : [
                ['account_id' => $varianceAccount, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
                ['account_id' => $cash, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1],
            ];

        $journal = app(AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'POS-VAR-'.$session->id.'-'.strtoupper(bin2hex(random_bytes(4))),
            'date' => optional($session->closed_at)->toDateString() ?: now()->toDateString(),
            'description' => 'POS cash variance session '.$session->id,
        ], $lines, $session);

        $session->update([
            'variance_journal_id' => $journal->id,
            'variance_accounting_status' => 'posted',
            'variance_accounting_message' => null,
        ]);
        return $journal;
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('company_id IS NULL')
            ->value('account_id');
    }
}
