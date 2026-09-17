<?php

namespace App\Services;

use App\Models\HrEmployee;
use App\Models\HrEmployeeBenefit;
use App\Models\HrPayRun;
use App\Models\HrPayrollRule;
use App\Models\HrAttendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HrPayrollService
{
    public function generate(HrPayRun $run): HrPayRun
    {
        if ($run->status !== 'draft') throw new RuntimeException('Only draft pay runs can be generated.');
        return DB::transaction(function () use ($run): HrPayRun {
            $employees = HrEmployee::where('company_id', $run->company_id)->where('status', 'active')->where('pay_frequency', $run->frequency)
                ->where(fn ($query) => $query->whereNull('hire_date')->orWhereDate('hire_date', '<=', $run->period_to))
                ->where(fn ($query) => $query->whereNull('termination_date')->orWhereDate('termination_date', '>=', $run->period_from))
                ->lockForUpdate()->get();
            $rules = HrPayrollRule::where('company_id', $run->company_id)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $run->period_to))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $run->period_from))
                ->orderBy('code')->get();
            $run->payslips()->delete();
            foreach ($employees as $employee) {
                $basic = (float) $employee->basic_salary; $earnings = 0.0; $deductions = 0.0; $employerContributions = []; $breakdown = []; $statutoryDeductions = [];
                foreach ($rules as $rule) {
                    $base = $rule->rule_type === 'earning' ? $basic : $basic + $earnings;
                    $amount = $rule->calculation === 'percentage' ? $base * (float) $rule->value / 100 : (float) $rule->value;
                    if ($rule->rule_type === 'earning') $earnings += $amount; else $deductions += $amount;
                    $breakdown[$rule->code] = ['name' => $rule->name, 'type' => $rule->rule_type, 'amount' => round($amount, 6), 'is_statutory' => (bool) $rule->is_statutory, 'authority' => $rule->statutory_authority, 'remittance_mapping_key' => $rule->remittance_mapping_key];
                    if ($rule->rule_type === 'deduction' && $rule->is_statutory && $rule->remittance_mapping_key) {
                        $statutoryDeductions[$rule->remittance_mapping_key] = ($statutoryDeductions[$rule->remittance_mapping_key] ?? 0) + $amount;
                    }
                    if ($rule->is_statutory && $rule->employer_value > 0 && $rule->employer_mapping_key) {
                        $employerBase = $basic + $earnings;
                        $employerAmount = $rule->employer_calculation === 'percentage' ? $employerBase * (float) $rule->employer_value / 100 : (float) $rule->employer_value;
                        $employerContributions[$rule->employer_mapping_key] = ($employerContributions[$rule->employer_mapping_key] ?? 0) + $employerAmount;
                        $breakdown[$rule->code]['employer_contribution'] = ['authority' => $rule->statutory_authority, 'mapping_key' => $rule->employer_mapping_key, 'amount' => round($employerAmount, 6)];
                    }
                }
                $benefits = HrEmployeeBenefit::where('company_id', $run->company_id)->where('employee_id', $employee->id)->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $run->period_to))
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $run->period_from))
                    ->orderBy('code')->get();
                foreach ($benefits as $benefit) {
                    $benefitBase = $basic + $earnings;
                    $amount = $benefit->calculation === 'percentage' ? $benefitBase * (float) $benefit->value / 100 : (float) $benefit->value;
                    if ($benefit->benefit_type === 'earning') $earnings += $amount; else $deductions += $amount;
                    $breakdown['benefit_'.$benefit->id] = ['name' => $benefit->name, 'code' => $benefit->code, 'type' => $benefit->benefit_type, 'amount' => round($amount, 6), 'calculation' => $benefit->calculation];
                }
                $gross = $basic + $earnings;
                $scheduledDays = Carbon::parse($run->period_from)->diffInDays(Carbon::parse($run->period_to)) + 1;
                $absentDays = 0;
                $attendanceDeduction = 0.0;
                if ($run->attendance_policy === 'unpaid_absence') {
                    $absentDays = HrAttendance::where('company_id', $run->company_id)->where('employee_id', $employee->id)
                        ->where('status', 'absent')->whereBetween('attendance_date', [$run->period_from, $run->period_to])->count();
                    $attendanceDeduction = $scheduledDays > 0 ? min($gross, $gross * $absentDays / $scheduledDays) : 0.0;
                    if ($attendanceDeduction > 0) $breakdown['attendance_absence'] = ['name' => 'Unpaid absence', 'type' => 'deduction', 'days' => $absentDays, 'amount' => round($attendanceDeduction, 6)];
                }
                $overtimeMinutes = 0; $overtimeAmount = 0.0;
                if ($run->overtime_policy === 'pay_overtime') {
                    $overtimeMinutes = (int) HrAttendance::where('company_id', $run->company_id)->where('employee_id', $employee->id)->where('status', 'present')->whereBetween('attendance_date', [$run->period_from, $run->period_to])->sum('overtime_minutes');
                    $overtimeAmount = $scheduledDays > 0 ? $gross / $scheduledDays / 480 * $overtimeMinutes * (float) $run->overtime_multiplier : 0.0;
                    if ($overtimeAmount > 0) $breakdown['overtime'] = ['name' => 'Overtime', 'type' => 'earning', 'minutes' => $overtimeMinutes, 'multiplier' => (float) $run->overtime_multiplier, 'amount' => round($overtimeAmount, 6)];
                }
                $gross += $overtimeAmount; $deductions += $attendanceDeduction; $employerTotal = array_sum($employerContributions); $net = max(0, $gross - $deductions);
                $run->payslips()->create(['company_id' => $run->company_id, 'employee_id' => $employee->id, 'scheduled_days' => $scheduledDays, 'absent_days' => $absentDays, 'attendance_deduction' => $attendanceDeduction, 'overtime_minutes' => $overtimeMinutes, 'overtime_amount' => $overtimeAmount, 'employer_contribution_total' => $employerTotal, 'gross_amount' => $gross, 'deduction_amount' => $deductions, 'net_amount' => $net, 'currency_code' => $employee->currency_code, 'deductions' => $breakdown, 'statutory_deductions' => $statutoryDeductions, 'employer_contributions' => $employerContributions]);
            }
            $run->update(['gross_total' => $run->payslips()->sum('gross_amount'), 'deduction_total' => $run->payslips()->sum('deduction_amount'), 'employer_contribution_total' => $run->payslips()->sum('employer_contribution_total'), 'net_total' => $run->payslips()->sum('net_amount')]);
            return $run->fresh('payslips.employee');
        });
    }
}
