<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use Illuminate\Support\Facades\DB;

class BankStatementReconciliationService
{
    public function calculate(BankAccount $account, string $statementDate, float $openingBalance, float $closingBalance): array
    {
        $lines = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->whereDate('transaction_date', '<=', $statementDate)
            ->get(['amount', 'status']);
        $bookBalance = $openingBalance + (float) $lines->sum(fn (BankStatementLine $line): float => (float) $line->amount);

        return [
            'book_balance' => round($bookBalance, 6),
            'difference' => round($closingBalance - $bookBalance, 6),
            'line_count' => $lines->count(),
            'unmatched_count' => $lines->where('status', 'unmatched')->count(),
        ];
    }

    public function createOrRefresh(BankAccount $account, string $statementDate, float $openingBalance, float $closingBalance, ?string $notes = null): array
    {
        return DB::transaction(function () use ($account, $statementDate, $openingBalance, $closingBalance, $notes): array {
            $reconciliation = BankReconciliation::query()
                ->where('company_id', $account->company_id)
                ->where('bank_account_id', $account->id)
                ->whereDate('statement_date', $statementDate)
                ->lockForUpdate()->first();
            if ($reconciliation?->status === 'closed') {
                throw new \RuntimeException('A closed bank reconciliation cannot be changed. Reopen it first.');
            }
            $values = $this->calculate($account, $statementDate, $openingBalance, $closingBalance);
            $created = !$reconciliation;
            $reconciliation = $reconciliation ?: new BankReconciliation(['company_id' => $account->company_id, 'bank_account_id' => $account->id, 'statement_date' => $statementDate]);
            $reconciliation->fill(array_merge([
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'notes' => $notes,
                'status' => 'draft',
            ], $values));
            $reconciliation->save();

            return [$reconciliation->fresh(['bankAccount']), $created];
        });
    }

    public function close(BankReconciliation $reconciliation, float $tolerance = 0.01): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $tolerance): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            if ($reconciliation->status === 'closed') return $reconciliation->fresh(['bankAccount']);
            $summary = $this->calculate($reconciliation->bankAccount, $reconciliation->statement_date->toDateString(), (float) $reconciliation->opening_balance, (float) $reconciliation->closing_balance);
            $reconciliation->fill($summary);
            if ($summary['unmatched_count'] > 0) throw new \RuntimeException('All statement lines must be matched, settled, or ignored before closing.');
            if (abs($summary['difference']) > $tolerance) throw new \RuntimeException('The bank reconciliation has a variance outside the allowed tolerance.');
            $reconciliation->status = 'closed';
            $reconciliation->closed_at = now();
            $reconciliation->closed_by = auth()->id();
            $reconciliation->save();

            return $reconciliation->fresh(['bankAccount', 'closer']);
        });
    }

    public function reopen(BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A reason is required to reopen a bank reconciliation.');
        $reconciliation->update(['status' => 'draft', 'closed_at' => null, 'closed_by' => null, 'notes' => trim(($reconciliation->notes ? $reconciliation->notes."\n" : '').'Reopened: '.$reason)]);
        return $reconciliation->fresh(['bankAccount']);
    }
}
