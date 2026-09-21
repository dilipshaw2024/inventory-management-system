<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    public function createDraft(array $header, array $lines): JournalEntry
    {
        if (!$lines) throw new \InvalidArgumentException('A journal requires at least one line.');
        return DB::transaction(function () use ($header, $lines): JournalEntry {
            $entry = JournalEntry::create(array_merge($header, ['created_by' => auth()->id(), 'status' => 'draft']));
            foreach ($lines as $line) {
                $this->assertLine($line);
                $accountId = (int) ($line['account_id'] ?? 0);
                if (!$this->accountIsAccessible($accountId, $header['company_id'] ?? null)) throw new \InvalidArgumentException('Journal account is invalid, inactive, or outside the journal company.');
                $this->assertDepartment($line, $header['company_id'] ?? null);
                $this->assertCostCenter($line, $header['company_id'] ?? null);
                $entry->lines()->create($line);
            }
            return $entry->load('lines.account');
        });
    }

    public function approve(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            $entry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($entry->getKey());
            if ($entry->status !== 'draft') throw new \RuntimeException('Only draft journals can be approved.');
            app(FiscalPeriodService::class)->assertOpen($entry->company_id, $entry->date->toDateString(), 'No open fiscal period exists for the journal date.');
            $debits = round((float) $entry->lines->sum('debit'), 6);
            $credits = round((float) $entry->lines->sum('credit'), 6);
            if ($debits <= 0 || abs($debits - $credits) > 0.000001) throw new \RuntimeException('Journal debits and credits must be equal and greater than zero.');
            $entry->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);
            return $entry->load('lines.account');
        });
    }

    public function post(array $header, array $lines, ?Model $source = null): JournalEntry
    {
        if (!$lines) throw new \InvalidArgumentException('A journal requires at least one line.');
        app(FiscalPeriodService::class)->assertOpen($header['company_id'] ?? null, $header['date'] ?? now()->toDateString(), 'No open fiscal period exists for the journal date.');
        $debits = round((float) collect($lines)->sum('debit'), 6);
        $credits = round((float) collect($lines)->sum('credit'), 6);
        if ($debits <= 0 || abs($debits - $credits) > 0.000001) throw new \InvalidArgumentException('Journal debits and credits must be equal and greater than zero.');
        return DB::transaction(function () use ($header, $lines, $source): JournalEntry {
            $entry = JournalEntry::create(array_merge($header, ['source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(), 'created_by' => auth()->id(), 'status' => 'draft']));
            foreach ($lines as $line) {
                $accountId = (int) ($line['account_id'] ?? 0);
                if (!$this->accountIsAccessible($accountId, $header['company_id'] ?? null)) throw new \InvalidArgumentException('Journal account is invalid, inactive, or outside the journal company.');
                $this->assertDepartment($line, $header['company_id'] ?? null);
                $this->assertCostCenter($line, $header['company_id'] ?? null);
                $debit = (float) ($line['debit'] ?? 0); $credit = (float) ($line['credit'] ?? 0);
                $this->assertLine($line);
                $entry->lines()->create($line);
            }
            $entry->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);
            return $entry->load('lines.account');
        });
    }

    private function assertLine(array $line): void
    {
        $debit = (float) ($line['debit'] ?? 0); $credit = (float) ($line['credit'] ?? 0);
        if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) throw new \InvalidArgumentException('Each journal line must contain either a debit or a credit.');
    }

    private function accountIsAccessible(int $accountId, ?int $companyId): bool
    {
        return ChartOfAccount::whereKey($accountId)->where('is_active', true)->where(function ($query) use ($companyId): void {
            $query->whereNull('company_id')->orWhere('company_id', $companyId);
        })->exists();
    }

    private function assertCostCenter(array $line, ?int $companyId): void
    {
        if (empty($line['cost_center_id'])) return;
        if (!CostCenter::whereKey($line['cost_center_id'])->where('is_active', true)->where(function ($query) use ($companyId): void { $query->whereNull('company_id')->orWhere('company_id', $companyId); })->exists()) throw new \InvalidArgumentException('Journal cost center is invalid, inactive, or outside the journal company.');
    }

    private function assertDepartment(array $line, ?int $companyId): void
    {
        if (empty($line['department_id'])) return;
        if (!\App\Models\Department::whereKey($line['department_id'])->where('is_active', true)->where(function ($query) use ($companyId): void { $query->whereNull('company_id')->orWhere('company_id', $companyId); })->exists()) {
            throw new \InvalidArgumentException('Journal department is invalid, inactive, or outside the journal company.');
        }
    }

    public function reverse(JournalEntry $entry, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason): JournalEntry {
            $entry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($entry->getKey());
            if ($entry->status !== 'posted') {
                throw new \RuntimeException('Only posted journals can be reversed.');
            }
            if ($entry->reversal()->exists()) {
                throw new \RuntimeException('This journal has already been reversed.');
            }

            $reversal = $this->post([
                'company_id' => $entry->company_id,
                'entry_no' => 'REV-'.$entry->entry_no.'-'.now()->format('YmdHis'),
                'date' => now()->toDateString(),
                'description' => $reason ?: 'Reversal of '.$entry->entry_no,
                'reversal_of_id' => $entry->id,
                'consolidation_elimination' => (bool) $entry->consolidation_elimination,
                'consolidation_reference' => $entry->consolidation_reference ? 'REV-'.$entry->consolidation_reference : null,
            ], $entry->lines->map(fn ($line) => array_merge($line->only([
                'account_id', 'currency_code', 'exchange_rate', 'department_id', 'cost_center_id', 'description',
            ]), ['debit' => $line->credit, 'credit' => $line->debit]))->all());

            $entry->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => auth()->id()]);
            return $reversal->load('lines.account');
        });
    }

}
