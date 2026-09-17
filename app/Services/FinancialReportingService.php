<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\AccountMapping;
use App\Models\JournalLine;

class FinancialReportingService
{
    public function cashFlow(int $companyId, string $from, string $to): array
    {
        $cashAccountIds = AccountMapping::whereIn('mapping_key', ['cash', 'bank', 'cash_bank'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('company_id IS NULL')->pluck('account_id')->unique()->values();
        if ($cashAccountIds->isEmpty()) {
            return ['rows' => collect(), 'summary' => ['opening_cash' => 0.0, 'operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'net_change' => 0.0, 'closing_cash' => 0.0], 'cash_accounts' => collect()];
        }
        $cashLines = JournalLine::with(['entry', 'account'])
            ->whereIn('account_id', $cashAccountIds)
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')->whereDate('date', '<=', $to)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->get();
        $openingCash = (float) $cashLines->filter(fn ($line): bool => $line->entry->date->toDateString() < $from)->sum(fn ($line): float => (float) $line->debit - (float) $line->credit);
        $periodLines = JournalLine::with(['entry', 'account'])
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')->whereBetween('date', [$from, $to])->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->get()->groupBy('journal_entry_id');
        $rows = $periodLines->map(function ($lines) use ($cashAccountIds): ?array {
            $cashChange = (float) $lines->whereIn('account_id', $cashAccountIds)->sum(fn ($line): float => (float) $line->debit - (float) $line->credit);
            if (abs($cashChange) <= 0.000001) return null;
            $counterparts = $lines->reject(fn ($line): bool => $cashAccountIds->contains((int) $line->account_id));
            $classification = $counterparts->contains(fn ($line): bool => in_array($line->account?->account_type, ['liability', 'equity'], true)) ? 'financing' : 'operating';
            return ['date' => $lines->first()->entry->date->toDateString(), 'entry_id' => $lines->first()->entry->id, 'entry_no' => $lines->first()->entry->entry_no, 'description' => $lines->first()->entry->description, 'classification' => $classification, 'cash_change' => $cashChange, 'counterpart_accounts' => $counterparts->pluck('account.code')->filter()->unique()->values()->all()];
        })->filter()->sortBy(['date', 'entry_id'])->values();
        $operating = (float) $rows->where('classification', 'operating')->sum('cash_change');
        $investing = (float) $rows->where('classification', 'investing')->sum('cash_change');
        $financing = (float) $rows->where('classification', 'financing')->sum('cash_change');
        return ['rows' => $rows, 'summary' => ['opening_cash' => $openingCash, 'operating' => $operating, 'investing' => $investing, 'financing' => $financing, 'net_change' => $operating + $investing + $financing, 'closing_cash' => $openingCash + $operating + $investing + $financing], 'cash_accounts' => ChartOfAccount::whereIn('id', $cashAccountIds)->orderBy('code')->get()];
    }

    public function statements(int $companyId, string $from, string $to, ?int $accountId = null): array
    {
        $accounts = ChartOfAccount::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($accountId, fn ($query) => $query->whereKey($accountId))
            ->orderBy('code')->get();
        $lines = JournalLine::with('entry')
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')->whereDate('date', '<=', $to)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->get()->groupBy('account_id');
        $rows = $accounts->map(function (ChartOfAccount $account) use ($lines, $from, $to): array {
            $period = $lines->get($account->id, collect())->filter(fn ($line): bool => $line->entry->date->toDateString() >= $from && $line->entry->date->toDateString() <= $to);
            $asOf = $lines->get($account->id, collect());
            $debit = (float) $period->sum('debit'); $credit = (float) $period->sum('credit');
            return [
                'account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type,
                'period_debit' => $debit, 'period_credit' => $credit,
                'as_of_debit' => (float) $asOf->sum('debit'), 'as_of_credit' => (float) $asOf->sum('credit'),
                'period_balance' => in_array($account->account_type, ['income', 'liability', 'equity'], true) ? $credit - $debit : $debit - $credit,
                'as_of_balance' => in_array($account->account_type, ['income', 'liability', 'equity'], true) ? (float) $asOf->sum('credit') - (float) $asOf->sum('debit') : (float) $asOf->sum('debit') - (float) $asOf->sum('credit'),
            ];
        })->filter(fn (array $row): bool => abs($row['period_debit']) > 0.000001 || abs($row['period_credit']) > 0.000001 || abs($row['as_of_debit']) > 0.000001 || abs($row['as_of_credit']) > 0.000001)->values();
        $profitAndLoss = $rows->filter(fn (array $row): bool => in_array($row['account_type'], ['income', 'expense'], true))->values();
        $balanceSheet = $rows->filter(fn (array $row): bool => in_array($row['account_type'], ['asset', 'liability', 'equity'], true))->values();
        $income = (float) $profitAndLoss->where('account_type', 'income')->sum('period_balance');
        $expense = (float) $profitAndLoss->where('account_type', 'expense')->sum('period_balance');
        $assets = (float) $balanceSheet->where('account_type', 'asset')->sum('as_of_balance');
        $liabilities = (float) $balanceSheet->where('account_type', 'liability')->sum('as_of_balance');
        $equity = (float) $balanceSheet->where('account_type', 'equity')->sum('as_of_balance');
        return [
            'profit_and_loss' => $profitAndLoss,
            'balance_sheet' => $balanceSheet,
            'summary' => ['income' => $income, 'expenses' => $expense, 'net_income' => $income - $expense, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'equity_and_net_income' => $equity + $income - $expense, 'balance_difference' => $assets - ($liabilities + $equity + $income - $expense)],
            'accounts' => $accounts,
        ];
    }
}
