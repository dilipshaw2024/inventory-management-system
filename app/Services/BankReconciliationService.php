<?php

namespace App\Services;

use App\Models\BankStatementLine;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BankReconciliationService
{
    public function suggestions(BankStatementLine $line): array
    {
        $companyId = $line->company_id ?: auth()->user()?->company_id;
        $amount = abs((float) $line->amount);
        $from = Carbon::parse($line->transaction_date)->subDays(7)->startOfDay();
        $to = Carbon::parse($line->transaction_date)->addDays(7)->endOfDay();
        $tolerance = 0.000001;
        $rows = $this->companyScope(Payment::with('customer'), $companyId)->where('approval_status', 'approved')->whereBetween('paid_amount', [$amount - $tolerance, $amount + $tolerance])
            ->where('paid_amount', '>', 0)->where(fn ($query) => $query->where('is_reversed', false)->orWhereNull('is_reversed'))
            ->whereBetween('created_at', [$from, $to])->get()->map(fn (Payment $payment): array => [
                'target_type' => 'customer_payment', 'target_id' => $payment->id, 'amount' => (float) $payment->paid_amount,
                'date' => ($payment->payment_date ?: $payment->created_at)?->toDateString(), 'label' => 'Customer receipt #'.$payment->id,
                'party' => $payment->customer?->name,
            ]);
        $rows = $rows->merge($this->companyScope(SupplierPayment::with('supplier'), $companyId)->where(function ($query) use ($amount, $tolerance): void {
            $query->whereBetween('base_amount', [$amount - $tolerance, $amount + $tolerance])->orWhereBetween('amount', [$amount - $tolerance, $amount + $tolerance]);
        })->where('status', 'approved')->where('is_reversed', false)->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->get()->map(fn (SupplierPayment $payment): array => [
            'target_type' => 'supplier_payment', 'target_id' => $payment->id, 'amount' => (float) ($payment->base_amount ?: $payment->amount),
            'date' => $payment->payment_date?->toDateString(), 'label' => 'Supplier payment #'.$payment->id,
            'party' => $payment->supplier?->name,
        ]));
        return $rows->sortBy(fn (array $row): array => [abs(Carbon::parse($row['date'])->diffInDays($line->transaction_date)), $row['target_id']])->take(20)->values()->all();
    }

    public function match(BankStatementLine $line, string $type, int $id): Model
    {
        return DB::transaction(function () use ($line, $type, $id): Model {
            $companyId = $line->company_id ?: auth()->user()?->company_id;
            $line = $this->companyScope(BankStatementLine::query(), $companyId)->lockForUpdate()->findOrFail($line->id);
            if ($line->status !== 'unmatched') throw new \RuntimeException('Only unmatched bank lines can be matched.');
            $model = match ($type) {
                'customer_payment' => $this->companyScope(Payment::query(), $companyId)->lockForUpdate()->findOrFail($id),
                'supplier_payment' => $this->companyScope(SupplierPayment::query(), $companyId)->lockForUpdate()->findOrFail($id),
                default => throw new \InvalidArgumentException('Unsupported reconciliation target.'),
            };
            if ($line->company_id && $model->company_id && (int) $line->company_id !== (int) $model->company_id) throw new \RuntimeException('Bank line and payment belong to different companies.');
            if ($type === 'supplier_payment' && $model->status !== 'approved') throw new \RuntimeException('Only approved supplier payments can be matched.');
            if ($type === 'customer_payment' && ($model->approval_status ?? 'approved') !== 'approved') throw new \RuntimeException('Only approved customer payments can be matched.');
            if ($type === 'customer_payment' && (float) $model->paid_amount <= 0) throw new \RuntimeException('Customer payment has no amount to match.');
            $targetAmount = (float) ($type === 'supplier_payment' ? ($model->base_amount ?: $model->amount) : $model->paid_amount);
            if (abs(abs((float) $line->amount) - $targetAmount) > 0.000001) throw new \RuntimeException('Statement amount does not match the selected payment.');
            $line->update([
                'status' => 'matched', 'matched_type' => $type, 'matched_id' => $model->id,
                'matched_at' => now(), 'matched_by' => auth()->id(), 'unmatch_reason' => null,
                'unmatched_at' => null, 'unmatched_by' => null,
            ]);
            return $model;
        });
    }

    public function setStatus(BankStatementLine $line, string $status): BankStatementLine
    {
        if (!in_array($status, ['unmatched', 'ignored'], true)) throw new \InvalidArgumentException('Unsupported bank line status.');
        return DB::transaction(function () use ($line, $status): BankStatementLine {
            $companyId = $line->company_id ?: auth()->user()?->company_id;
            $line = $this->companyScope(BankStatementLine::query(), $companyId)->lockForUpdate()->findOrFail($line->id);
            if ($line->status === 'matched' && $status !== 'unmatched') throw new \RuntimeException('Matched bank lines must be unmatched through a controlled reversal.');
            if ($line->settlement_journal_id) throw new \RuntimeException('Settled bank lines require a journal reversal before they can be reopened.');
            $line->update(['status' => $status, 'matched_type' => null, 'matched_id' => null, 'matched_at' => null, 'matched_by' => null]);
            return $line->fresh();
        });
    }

    public function reverseMatch(BankStatementLine $line, string $reason): BankStatementLine
    {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('A reason is required to reverse a bank match.');

        return DB::transaction(function () use ($line, $reason): BankStatementLine {
            $companyId = $line->company_id ?: auth()->user()?->company_id;
            $line = $this->companyScope(BankStatementLine::query(), $companyId)->lockForUpdate()->findOrFail($line->id);
            if ($line->status !== 'matched') throw new \RuntimeException('Only matched bank lines can have their match reversed.');
            if ($line->settlement_journal_id) throw new \RuntimeException('Settled bank lines require a journal reversal before their match can be reversed.');
            $line->update([
                'status' => 'unmatched', 'matched_type' => null, 'matched_id' => null,
                'matched_at' => null, 'matched_by' => null, 'unmatch_reason' => $reason,
                'unmatched_at' => now(), 'unmatched_by' => auth()->id(),
            ]);
            return $line->fresh();
        });
    }

    public function settle(BankStatementLine $line, int $counterAccountId, string $reason): BankStatementLine
    {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('A reason is required to settle a bank line.');
        if ($line->settlement_journal_id) return $line->fresh(['bankAccount', 'settlementJournal', 'settlementAccount']);
        return DB::transaction(function () use ($line, $counterAccountId, $reason): BankStatementLine {
            $companyId = $line->company_id ?: auth()->user()?->company_id;
            $line = $this->companyScope(BankStatementLine::with('bankAccount'), $companyId)->lockForUpdate()->findOrFail($line->id);
            if ($line->settlement_journal_id) return $line->fresh(['bankAccount', 'settlementJournal', 'settlementAccount']);
            if ($line->status !== 'unmatched') throw new \RuntimeException('Only unmatched bank lines can be settled.');
            $bankAccount = $line->bankAccount;
            if (!$bankAccount?->gl_account_id) throw new \RuntimeException('The bank account must be linked to a GL account before settlement.');
            $counter = ChartOfAccount::whereKey($counterAccountId)->where('is_active', true)->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))->first();
            if (!$counter) throw new \RuntimeException('The settlement account is invalid, inactive, or outside the company.');
            $amount = abs((float) $line->amount);
            $inflow = (float) $line->amount > 0;
            $journal = app(AccountingService::class)->post([
                'company_id' => $companyId, 'entry_no' => 'BANK-SET-'.$line->id, 'external_reference' => 'bank-line-settlement:'.$line->id,
                'date' => $line->transaction_date->toDateString(), 'description' => 'Bank statement settlement '.$line->reference,
            ], $inflow ? [
                ['account_id' => $bankAccount->gl_account_id, 'debit' => $amount, 'credit' => 0, 'description' => $reason],
                ['account_id' => $counter->id, 'debit' => 0, 'credit' => $amount, 'description' => $reason],
            ] : [
                ['account_id' => $counter->id, 'debit' => $amount, 'credit' => 0, 'description' => $reason],
                ['account_id' => $bankAccount->gl_account_id, 'debit' => 0, 'credit' => $amount, 'description' => $reason],
            ], $line);
            $line->update(['status' => 'matched', 'matched_type' => 'journal_entry', 'matched_id' => $journal->id, 'matched_at' => now(), 'matched_by' => auth()->id(), 'settlement_journal_id' => $journal->id, 'settlement_account_id' => $counter->id, 'settlement_reason' => $reason, 'settled_at' => now(), 'settled_by' => auth()->id()]);
            return $line->fresh(['bankAccount', 'settlementJournal', 'settlementAccount']);
        });
    }

    public function reverseSettlement(BankStatementLine $line, string $reason): BankStatementLine
    {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('A reason is required to reverse a bank settlement.');
        return DB::transaction(function () use ($line, $reason): BankStatementLine {
            $companyId = $line->company_id ?: auth()->user()?->company_id;
            $line = $this->companyScope(BankStatementLine::query(), $companyId)->lockForUpdate()->findOrFail($line->id);
            if (!$line->settlement_journal_id) throw new \RuntimeException('This bank line has no settlement journal.');
            if ($line->settlement_reversal_journal_id) throw new \RuntimeException('This bank settlement has already been reversed.');
            $journal = \App\Models\JournalEntry::where('company_id', $companyId)->findOrFail($line->settlement_journal_id);
            $reversal = app(AccountingService::class)->reverse($journal, $reason);
            $line->update(['status' => 'unmatched', 'matched_type' => null, 'matched_id' => null, 'matched_at' => null, 'matched_by' => null, 'unmatched_at' => now(), 'unmatched_by' => auth()->id(), 'unmatch_reason' => $reason, 'settlement_reversal_journal_id' => $reversal->id, 'settlement_reversal_reason' => $reason, 'settlement_reversed_at' => now(), 'settlement_reversed_by' => auth()->id()]);
            return $line->fresh(['settlementJournal', 'settlementReversalJournal', 'settlementAccount']);
        });
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
