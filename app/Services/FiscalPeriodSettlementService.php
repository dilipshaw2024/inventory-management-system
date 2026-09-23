<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class FiscalPeriodSettlementService
{
    public function settle(FiscalPeriod $period, ?int $settledBy = null): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $settledBy): FiscalPeriod {
            $period = FiscalPeriod::withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== 'open') throw new \RuntimeException('Only open fiscal periods can be settled.');
            if (in_array($period->settlement_status, ['posted', 'reversed'], true)) return $period->fresh();

            $rows = $this->profitAndLossRows($period);
            if ($rows->isEmpty()) {
                $period->update(['settlement_status' => 'not_required', 'settled_by' => $settledBy, 'settled_at' => now()]);
                return $period->fresh();
            }

            $retainedEarnings = AccountMapping::withoutGlobalScopes()
                ->where('company_id', $period->company_id)->where('mapping_key', 'retained_earnings')
                ->with('account')->first()?->account;
            if (!$retainedEarnings || !$retainedEarnings->is_active || $retainedEarnings->account_type !== 'equity') {
                throw new \RuntimeException('Configure an active equity account with the retained_earnings mapping before closing this fiscal period.');
            }

            $lines = $rows->map(function (array $row): array {
                $signed = round((float) $row['debit'] - (float) $row['credit'], 6);
                return [
                    'account_id' => $row['account_id'],
                    'debit' => $signed < 0 ? abs($signed) : 0,
                    'credit' => $signed > 0 ? $signed : 0,
                    'currency_code' => $row['currency_code'],
                    'exchange_rate' => 1,
                ];
            })->filter(fn (array $line): bool => $line['debit'] > 0 || $line['credit'] > 0)->values()->all();
            $netIncome = round((float) collect($lines)->sum('debit') - (float) collect($lines)->sum('credit'), 6);
            if (abs($netIncome) <= 0.000001) {
                $period->update(['settlement_status' => 'not_required', 'settled_by' => $settledBy, 'settled_at' => now()]);
                return $period->fresh();
            }
            $lines[] = [
                'account_id' => $retainedEarnings->id,
                'debit' => $netIncome < 0 ? abs($netIncome) : 0,
                'credit' => $netIncome > 0 ? $netIncome : 0,
                'currency_code' => $this->currency($period->company_id),
                'exchange_rate' => 1,
            ];
            $journal = app(AccountingService::class)->post([
                'company_id' => $period->company_id,
                'entry_no' => 'JE-FP-'.$period->id.'-'.now()->format('YmdHis'),
                'date' => $period->ends_on->toDateString(),
                'description' => 'Fiscal period settlement '.$period->name,
            ], $lines, $period);
            $period->update(['settlement_status' => 'posted', 'settlement_journal_id' => $journal->id, 'settled_by' => $settledBy, 'settled_at' => now()]);
            app(AuditService::class)->record('fiscal_period.settled', $period, null, $period->fresh()->toArray());
            return $period->fresh();
        });
    }

    public function reverse(FiscalPeriod $period, string $reason): FiscalPeriod
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A settlement reversal reason is required.');
        return DB::transaction(function () use ($period, $reason): FiscalPeriod {
            $period = FiscalPeriod::withoutGlobalScopes()->lockForUpdate()->findOrFail($period->id);
            if ($period->settlement_status !== 'posted' || !$period->settlement_journal_id) return $period->fresh();
            $journal = JournalEntry::withoutGlobalScopes()->findOrFail($period->settlement_journal_id);
            $period->update(['status' => 'open']);
            $reversal = app(AccountingService::class)->reverse($journal, $reason, $period->ends_on->toDateString());
            $period->update(['settlement_status' => 'reversed', 'settlement_reversal_journal_id' => $reversal->id, 'settlement_reversal_reason' => $reason]);
            app(AuditService::class)->record('fiscal_period.settlement_reversed', $period, null, $period->fresh()->toArray());
            return $period->fresh();
        });
    }

    private function profitAndLossRows(FiscalPeriod $period)
    {
        $entries = JournalEntry::withoutGlobalScopes()->with(['lines' => function ($query): void {
            $query->withoutGlobalScopes()->with('account');
        }])->where('company_id', $period->company_id)->where('status', 'posted')
            ->whereDate('date', '>=', $period->starts_on)->whereDate('date', '<=', $period->ends_on)->get();
        return $entries->flatMap->lines->filter(fn ($line): bool => in_array($line->account?->account_type, ['income', 'expense'], true))
            ->groupBy('account_id')->map(function ($lines): array {
                $first = $lines->first();
                return ['account_id' => $first->account_id, 'debit' => (float) $lines->sum('debit'), 'credit' => (float) $lines->sum('credit'), 'currency_code' => strtoupper((string) ($first->currency_code ?: 'USD'))];
            })->values();
    }

    private function currency(int $companyId): string
    {
        return strtoupper((string) (\App\Models\Company::withoutGlobalScopes()->find($companyId)?->base_currency ?: 'USD'));
    }
}
