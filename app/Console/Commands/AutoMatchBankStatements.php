<?php

namespace App\Console\Commands;

use App\Models\BankStatementLine;
use App\Services\AuditService;
use App\Services\BankReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoMatchBankStatements extends Command
{
    protected $signature = 'erp:accounting:auto-match-bank-statements
        {--company= : Limit matching to a company ID}
        {--account= : Limit matching to a bank account ID}
        {--from= : Inclusive transaction date}
        {--to= : Inclusive transaction date}
        {--dry-run : Report safe matches without changing statement lines}';

    protected $description = 'Conservatively auto-match unambiguous bank statement lines to approved payments';

    public function handle(BankReconciliationService $reconciliation): int
    {
        $lines = BankStatementLine::query()
            ->where('status', 'unmatched')
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', (int) $company))
            ->when($this->option('account'), fn ($query, $account) => $query->where('bank_account_id', (int) $account))
            ->when($this->option('from'), fn ($query, $date) => $query->whereDate('transaction_date', '>=', Carbon::parse((string) $date)->toDateString()))
            ->when($this->option('to'), fn ($query, $date) => $query->whereDate('transaction_date', '<=', Carbon::parse((string) $date)->toDateString()))
            ->orderBy('id');

        $matched = 0;
        $candidates = 0;
        $errors = 0;
        foreach ($lines->cursor() as $line) {
            try {
                $result = $this->option('dry-run')
                    ? $this->singleCandidate($reconciliation, $line)
                    : $reconciliation->autoMatch($line);
                if (!$result) continue;
                $candidate = $result['candidate'];
                $candidates++;
                if ($this->option('dry-run')) {
                    $this->line("Would match bank line {$line->id} to {$candidate['target_type']} #{$candidate['target_id']}.");
                    continue;
                }

                $before = ['status' => 'unmatched'];
                $target = $result['target'];
                app(AuditService::class)->record('bank_statement_line.auto_matched', $line->fresh(), $before, [
                    'status' => 'matched', 'matched_type' => $candidate['target_type'],
                    'matched_id' => $target->id, 'automated' => true,
                ]);
                $matched++;
            } catch (\Throwable $exception) {
                $errors++;
                $this->error("Failed bank line {$line->id}: {$exception->getMessage()}");
            }
        }

        $this->line("Bank auto-match complete: {$matched} matched, {$candidates} safe candidate(s), {$errors} error(s).");
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function singleCandidate(BankReconciliationService $reconciliation, BankStatementLine $line): ?array
    {
        $suggestions = collect($reconciliation->suggestions($line))->filter(function (array $candidate) use ($line): bool {
            return abs(Carbon::parse($candidate['date'])->diffInDays(Carbon::parse($line->transaction_date), false)) <= 1;
        })->values();
        return $suggestions->count() === 1 ? ['candidate' => $suggestions->first()] : null;
    }
}
