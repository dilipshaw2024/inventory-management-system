<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\HrPayRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HrPayrollAccountingService
{
    public function post(HrPayRun $run): HrPayRun
    {
        if ($run->journal_entry_id) return $run->fresh('journal');
        if ($run->status !== 'approved') throw new RuntimeException('Only approved pay runs can be posted to accounting.');
        $expense = $this->account('payroll_expense', $run->company_id);
        $payable = $this->account('payroll_payable', $run->company_id);
        $deductionPayable = $this->account('payroll_deductions', $run->company_id);
        if (!$expense || !$payable) throw new RuntimeException('Configure payroll_expense and payroll_payable account mappings before approving a pay run.');
        $deduction = (float) $run->deduction_total;
        $statutory = [];
        foreach ($run->payslips()->get(['statutory_deductions', 'employer_contributions']) as $payslip) {
            foreach (($payslip->statutory_deductions ?: []) as $mappingKey => $amount) $statutory[$mappingKey] = ($statutory[$mappingKey] ?? 0) + (float) $amount;
            foreach (($payslip->employer_contributions ?: []) as $mappingKey => $amount) $statutory[$mappingKey] = ($statutory[$mappingKey] ?? 0) + (float) $amount;
        }
        $statutoryLines = [];
        $mappedStatutory = 0.0;
        foreach ($statutory as $mappingKey => $amount) {
            $account = $this->account($mappingKey, $run->company_id);
            if ($account && $amount > 0) { $statutoryLines[] = ['account_id' => $account, 'debit' => 0, 'credit' => $amount, 'description' => 'Statutory deduction '.$mappingKey.' '.$run->run_no]; $mappedStatutory += $amount; }
        }
        $unmappedDeduction = max(0, $deduction - $mappedStatutory);
        $lines = [['account_id' => $expense, 'debit' => (float) $run->gross_total + (float) $run->employer_contribution_total, 'credit' => 0, 'description' => 'Payroll expense '.$run->run_no]];
        if ($deduction > 0 && ($deductionPayable || $statutoryLines)) {
            $lines[] = ['account_id' => $payable, 'debit' => 0, 'credit' => (float) $run->net_total, 'description' => 'Net payroll payable '.$run->run_no];
            $lines = array_merge($lines, $statutoryLines);
            if ($unmappedDeduction > 0 && $deductionPayable) $lines[] = ['account_id' => $deductionPayable, 'debit' => 0, 'credit' => $unmappedDeduction, 'description' => 'Payroll deductions payable '.$run->run_no];
            if ($unmappedDeduction > 0 && !$deductionPayable) throw new RuntimeException('Configure payroll_deductions for deductions without a mapped statutory remittance account.');
        } else {
            $lines[] = ['account_id' => $payable, 'debit' => 0, 'credit' => (float) $run->gross_total, 'description' => 'Payroll payable '.$run->run_no];
        }
        $journal = app(AccountingService::class)->post(['company_id' => $run->company_id, 'entry_no' => 'PAY-'.$run->run_no, 'external_reference' => 'hr-pay-run:'.$run->id, 'date' => ($run->pay_date ?: $run->period_to)->toDateString(), 'description' => 'Payroll accounting for '.$run->run_no], $lines, $run);
        return DB::transaction(function () use ($run, $journal): HrPayRun {
            $run->update(['journal_entry_id' => $journal->id]);
            return $run->fresh(['journal', 'payslips.employee']);
        });
    }

    private function account(string $key, int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
