<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalLine;

class ConsolidatedReportingService
{
    public function trialBalance(int $rootCompanyId, string $from, string $to, ?string $reportingCurrency = null): array
    {
        $root = Company::query()->whereKey($rootCompanyId)->where('is_active', true)->firstOrFail();
        $companyIds = $this->companyTree($root->id);
        $companies = Company::query()->whereIn('id', $companyIds)->where('is_active', true)->orderBy('id')->get();
        $currency = strtoupper($reportingCurrency ?: ($root->consolidation_currency ?: $root->base_currency ?: 'USD'));
        $rates = [];
        foreach ($companies as $company) {
            $rates[$company->id] = app(CurrencyConversionService::class)->rate(strtoupper($company->base_currency ?: 'USD'), $currency, $to);
        }

        $lines = JournalLine::withoutGlobalScopes()->with([
            'entry' => fn ($query) => $query->withoutGlobalScopes(),
            'account' => fn ($query) => $query->withoutGlobalScopes(),
        ])->whereHas('entry', function ($query) use ($companyIds, $to): void {
            $query->withoutGlobalScopes()->where('status', 'posted')->whereDate('date', '<=', $to)->whereIn('company_id', $companyIds);
        })->get();

        $groups = [];
        $eliminationJournalIds = [];
        $automaticEliminationJournalIds = $this->automaticEliminationJournalIds($lines, $rates);
        foreach ($lines as $line) {
            $companyId = (int) $line->entry->company_id;
            $account = $line->account;
            if (!$account || !isset($rates[$companyId])) continue;
            if (isset($automaticEliminationJournalIds[(int) $line->entry->id])) continue;
            if ($line->entry->consolidation_elimination) $eliminationJournalIds[(int) $line->entry->id] = true;
            $key = $this->accountKey($account);
            $groups[$key] ??= ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type, 'opening_debit' => 0.0, 'opening_credit' => 0.0, 'period_debit' => 0.0, 'period_credit' => 0.0, 'companies' => []];
            $factor = (float) $rates[$companyId];
            $debit = round((float) $line->debit * $factor, 6);
            $credit = round((float) $line->credit * $factor, 6);
            $bucket = $line->entry->date->toDateString() < $from ? 'opening' : 'period';
            $groups[$key][$bucket.'_debit'] += $debit;
            $groups[$key][$bucket.'_credit'] += $credit;
            $groups[$key]['companies'][$companyId] ??= ['company_id' => $companyId, 'company_name' => $companies->firstWhere('id', $companyId)?->name, 'debit' => 0.0, 'credit' => 0.0, 'exchange_rate' => $factor];
            $groups[$key]['companies'][$companyId]['debit'] += $debit;
            $groups[$key]['companies'][$companyId]['credit'] += $credit;
        }

        $rows = collect($groups)->map(function (array $row): array {
            $row['closing_debit'] = $row['opening_debit'] + $row['period_debit'];
            $row['closing_credit'] = $row['opening_credit'] + $row['period_credit'];
            $row['net_balance'] = $row['closing_debit'] - $row['closing_credit'];
            $row['companies'] = array_values($row['companies']);
            return $row;
        })->filter(fn (array $row): bool => abs($row['closing_debit']) > 0.000001 || abs($row['closing_credit']) > 0.000001)->sortBy('code')->values();

        return ['rows' => $rows, 'companies' => $companies, 'reporting_currency' => $currency, 'rates' => $rates, 'elimination_journal_count' => count($eliminationJournalIds), 'automatic_elimination_journal_count' => count($automaticEliminationJournalIds), 'summary' => [
            'opening_debit' => (float) $rows->sum('opening_debit'), 'opening_credit' => (float) $rows->sum('opening_credit'),
            'period_debit' => (float) $rows->sum('period_debit'), 'period_credit' => (float) $rows->sum('period_credit'),
            'closing_debit' => (float) $rows->sum('closing_debit'), 'closing_credit' => (float) $rows->sum('closing_credit'), 'net_balance' => (float) $rows->sum('net_balance'),
        ]];
    }

    public function financialStatements(int $rootCompanyId, string $from, string $to, ?string $reportingCurrency = null): array
    {
        $trialBalance = $this->trialBalance($rootCompanyId, $from, $to, $reportingCurrency);
        $rows = $trialBalance['rows'];
        $profitAndLoss = $rows->filter(fn (array $row): bool => in_array($row['account_type'], ['income', 'expense'], true))->map(function (array $row): array {
            $amount = $row['account_type'] === 'income' ? -(float) $row['net_balance'] : (float) $row['net_balance'];
            return ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'account_type' => $row['account_type'], 'amount' => round($amount, 6), 'companies' => $row['companies']];
        })->values();
        $balanceSheet = $rows->filter(fn (array $row): bool => in_array($row['account_type'], ['asset', 'liability', 'equity'], true))->map(function (array $row): array {
            $amount = in_array($row['account_type'], ['liability', 'equity'], true) ? -(float) $row['net_balance'] : (float) $row['net_balance'];
            return ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'account_type' => $row['account_type'], 'amount' => round($amount, 6), 'companies' => $row['companies']];
        })->values();
        return $trialBalance + ['profit_and_loss' => $profitAndLoss, 'balance_sheet' => $balanceSheet, 'statement_summary' => [
            'income' => round((float) $profitAndLoss->where('account_type', 'income')->sum('amount'), 6),
            'expense' => round((float) $profitAndLoss->where('account_type', 'expense')->sum('amount'), 6),
            'net_income' => round((float) $profitAndLoss->where('account_type', 'income')->sum('amount') + (float) $profitAndLoss->where('account_type', 'expense')->sum('amount'), 6),
            'assets' => round((float) $balanceSheet->where('account_type', 'asset')->sum('amount'), 6),
            'liabilities' => round((float) $balanceSheet->where('account_type', 'liability')->sum('amount'), 6),
            'equity' => round((float) $balanceSheet->where('account_type', 'equity')->sum('amount'), 6),
        ]];
    }

    private function companyTree(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier) {
            $children = Company::query()->whereIn('parent_company_id', $frontier)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $children = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }
        return $ids;
    }

    private function automaticEliminationJournalIds($lines, array $rates): array
    {
        $matches = [];
        $candidates = $lines->filter(fn ($line): bool => (bool) $line->entry->intercompany_reference && $line->entry->counterparty_company_id)
            ->groupBy(fn ($line): string => (string) $line->entry->intercompany_reference);
        foreach ($candidates as $entries) {
            $companyIds = $entries->pluck('entry.company_id')->unique()->values();
            $counterpartyIds = $entries->pluck('entry.counterparty_company_id')->map(fn ($id): int => (int) $id)->unique()->values();
            if ($companyIds->count() < 2 || $counterpartyIds->count() !== $companyIds->count() || $counterpartyIds->diff($companyIds)->isNotEmpty()) continue;
            $accountTotals = $entries->groupBy(fn ($line): string => $this->accountKey($line->account))->map(function ($accountLines) use ($rates): float {
                return (float) $accountLines->sum(fn ($line): float => (((float) $line->debit - (float) $line->credit) * (float) ($rates[(int) $line->entry->company_id] ?? 1)));
            });
            if ($accountTotals->every(fn (float $total): bool => abs($total) <= 0.000001)) {
                foreach ($entries->pluck('entry.id')->unique() as $entryId) $matches[(int) $entryId] = true;
            }
        }
        return $matches;
    }

    private function accountKey(ChartOfAccount $account): string
    {
        return strtoupper((string) $account->code).'|'.strtolower((string) $account->account_type);
    }
}
