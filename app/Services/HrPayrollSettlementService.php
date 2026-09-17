<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\HrPayRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HrPayrollSettlementService
{
    public function settle(HrPayRun $run, string $paidAt, ?string $reference = null): HrPayRun
    {
        if ($run->settlement_journal_entry_id) return $run->fresh('settlementJournal');
        if ($run->status !== 'approved') throw new RuntimeException('Only approved pay runs can be settled.');
        $payable = $this->account('payroll_payable', $run->company_id);
        $cash = $this->account('cash_bank', $run->company_id) ?: $this->account('bank', $run->company_id) ?: $this->account('cash', $run->company_id);
        if (!$payable || !$cash) throw new RuntimeException('Configure payroll_payable and cash, bank, or cash_bank account mappings before settlement.');
        $journal = app(AccountingService::class)->post([
            'company_id' => $run->company_id, 'entry_no' => 'PAY-SET-'.$run->run_no, 'external_reference' => 'hr-pay-run-settlement:'.$run->id,
            'date' => $paidAt, 'description' => 'Payroll settlement for '.$run->run_no,
        ], [
            ['account_id' => $payable, 'debit' => (float) $run->net_total, 'credit' => 0, 'description' => 'Settle payroll payable '.$run->run_no],
            ['account_id' => $cash, 'debit' => 0, 'credit' => (float) $run->net_total, 'description' => 'Payroll payment '.$run->run_no],
        ], $run);
        return DB::transaction(function () use ($run, $journal, $paidAt, $reference): HrPayRun {
            $run->update(['status' => 'paid', 'paid_at' => $paidAt, 'payment_reference' => $reference, 'settlement_journal_entry_id' => $journal->id]);
            return $run->fresh(['journal', 'settlementJournal', 'payslips.employee']);
        });
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
