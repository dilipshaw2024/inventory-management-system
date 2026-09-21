<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\WarrantyClaim;

class WarrantyClaimAccountingService
{
    public function post(WarrantyClaim $claim, string $mode): array
    {
        if ($mode === 'none') return ['status' => 'not_posted', 'journal_entry_id' => null, 'missing_mappings' => []];
        $companyId = (int) $claim->company_id;
        $accounts = $mode === 'customer_reimbursement'
            ? [['key' => 'warranty_expense', 'debit' => true], ['keys' => ['cash_bank', 'cash', 'bank'], 'debit' => false]]
            : [['keys' => ['warranty_receivable'], 'debit' => true], ['keys' => ['warranty_recovery', 'supplier_claim_recovery'], 'debit' => false]];
        $resolved = [];
        $missing = [];
        foreach ($accounts as $definition) {
            $keys = $definition['keys'] ?? [$definition['key']];
            $mapping = AccountMapping::whereIn('mapping_key', $keys)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->first();
            $accountId = $mapping?->account_id;
            $resolved[] = ['account_id' => $accountId, 'debit' => $definition['debit']];
            if (!$accountId) $missing[] = $keys[0];
        }
        if ($missing) return ['status' => 'missing_mapping', 'journal_entry_id' => null, 'missing_mappings' => $missing];
        $currency = strtoupper($claim->settlement_currency ?: (Company::find($companyId)?->base_currency ?: 'USD'));
        $amount = (float) $claim->settlement_amount;
        $lines = array_map(fn (array $line): array => ['account_id' => $line['account_id'], 'debit' => $line['debit'] ? $amount : 0, 'credit' => $line['debit'] ? 0 : $amount, 'currency_code' => $currency, 'exchange_rate' => 1, 'description' => 'Warranty claim settlement'], $resolved);
        $journal = app(AccountingService::class)->post([
            'company_id' => $companyId, 'entry_no' => 'JE-WARRANTY-'.strtoupper(bin2hex(random_bytes(5))),
            'date' => $claim->settled_at?->toDateString() ?: now()->toDateString(),
            'description' => 'Warranty claim settlement '.$claim->claim_no,
        ], $lines, $claim);
        return ['status' => 'posted', 'journal_entry_id' => $journal->id, 'missing_mappings' => []];
    }
}
