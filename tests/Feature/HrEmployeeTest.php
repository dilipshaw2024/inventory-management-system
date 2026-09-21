<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\HrEmployee;
use App\Models\HrPayslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrEmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_employee_api_is_company_scoped_and_supports_lifecycle(): void
    {
        $company = Company::create(['name' => 'HR Co', 'code' => 'HR-CO']);
        $other = Company::create(['name' => 'Other Co', 'code' => 'OTHER-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        HrEmployee::create(['company_id' => $other->id, 'employee_no' => 'OTHER-1', 'first_name' => 'Other', 'status' => 'active', 'pay_frequency' => 'monthly', 'employment_type' => 'full_time', 'basic_salary' => 10]);
        Sanctum::actingAs($user, ['hr:read', 'hr:write']);
        $created = $this->postJson('/api/hr/employees', ['employee_no' => 'EMP-1', 'first_name' => 'Asha', 'employment_type' => 'full_time', 'pay_frequency' => 'monthly', 'basic_salary' => 50000, 'currency_code' => 'inr'])->assertCreated()->assertJsonPath('data.currency_code', 'INR');
        $id = $created->json('data.id');
        $this->getJson('/api/hr/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_no', 'EMP-1');
        $this->postJson('/api/hr/employees', ['employee_no' => 'EMP-CURSOR', 'first_name' => 'Cursor', 'employment_type' => 'full_time', 'pay_frequency' => 'weekly', 'basic_salary' => 1000])->assertCreated();
        $cursorPage = $this->getJson('/api/hr/employees?cursor_mode=1&per_page=1')->assertOk()->assertJsonPath('meta.feed', 'hr.employees')->assertJsonPath('meta.has_more', true);
        $this->assertNotEmpty($cursorPage->json('meta.next_cursor'));
        $this->getJson('/api/hr/employees?cursor='.urlencode($cursorPage->json('meta.next_cursor')).'&per_page=1')->assertOk()->assertJsonPath('data.0.employee_no', 'EMP-CURSOR');
        $this->postJson('/api/hr/employees/'.$id.'/deactivate')->assertOk()->assertJsonPath('data.status', 'terminated');
        $active = $this->postJson('/api/hr/employees', ['employee_no' => 'EMP-2', 'first_name' => 'Ravi', 'employment_type' => 'full_time', 'pay_frequency' => 'monthly', 'basic_salary' => 25000, 'currency_code' => 'inr'])->assertCreated();
        $this->postJson('/api/hr/attendance', ['employee_id' => $active->json('data.id'), 'attendance_date' => '2026-09-09', 'status' => 'present', 'check_in' => '09:00', 'check_out' => '17:30', 'scheduled_minutes' => 480])->assertOk()->assertJsonPath('data.worked_minutes', 510)->assertJsonPath('data.overtime_minutes', 30);
        $this->getJson('/api/hr/attendance?from=2026-09-01&to=2026-09-30&status=present')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/hr/attendance?cursor_mode=1&from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonPath('meta.feed', 'hr.attendance')->assertJsonCount(1, 'data');
        $leaveType = $this->postJson('/api/hr/leave-types', ['code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_entitlement' => 20])->assertCreated();
        $this->getJson('/api/hr/leave-types?cursor_mode=1')->assertOk()->assertJsonPath('meta.feed', 'hr.leave-types')->assertJsonCount(1, 'data');
        $leave = $this->postJson('/api/hr/leave-requests', ['employee_id' => $active->json('data.id'), 'leave_type_id' => $leaveType->json('data.id'), 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-12', 'reason' => 'Personal'])->assertCreated()->assertJsonPath('data.days', '3.000');
        $this->getJson('/api/hr/leave-requests?cursor_mode=1')->assertOk()->assertJsonPath('meta.feed', 'hr.leave-requests')->assertJsonCount(1, 'data');
        $this->postJson('/api/hr/leave-requests/'.$leave->json('data.id').'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/hr/leave-requests', ['employee_id' => $active->json('data.id'), 'leave_type_id' => $leaveType->json('data.id'), 'starts_on' => '2026-09-12', 'ends_on' => '2026-09-13'])->assertStatus(422);
        $weekly = $this->postJson('/api/hr/employees', ['employee_no' => 'EMP-W1', 'first_name' => 'Maya', 'employment_type' => 'full_time', 'pay_frequency' => 'weekly', 'basic_salary' => 700])->assertCreated();
        $this->postJson('/api/hr/attendance', ['employee_id' => $weekly->json('data.id'), 'attendance_date' => '2026-09-14', 'status' => 'absent'])->assertOk();
        $this->postJson('/api/hr/attendance', ['employee_id' => $weekly->json('data.id'), 'attendance_date' => '2026-09-15', 'status' => 'present', 'check_in' => '09:00', 'check_out' => '18:00', 'scheduled_minutes' => 480])->assertOk()->assertJsonPath('data.overtime_minutes', 60);
        $meal = $this->postJson('/api/hr/employees/'.$weekly->json('data.id').'/benefits', ['code' => 'MEAL', 'name' => 'Meal allowance', 'benefit_type' => 'earning', 'calculation' => 'fixed', 'value' => 50])->assertCreated()->assertJsonPath('data.code', 'MEAL');
        $insurance = $this->postJson('/api/hr/employees/'.$weekly->json('data.id').'/benefits', ['code' => 'INSURANCE', 'name' => 'Insurance', 'benefit_type' => 'deduction', 'calculation' => 'fixed', 'value' => 20])->assertCreated();
        $this->getJson('/api/hr/employees/'.$weekly->json('data.id').'/benefits')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/hr/payroll-rules', ['code' => 'HRA', 'name' => 'Housing allowance', 'rule_type' => 'earning', 'calculation' => 'percentage', 'value' => 5])->assertCreated();
        $this->postJson('/api/hr/payroll-rules', ['code' => 'TAX', 'name' => 'Payroll tax', 'rule_type' => 'deduction', 'calculation' => 'percentage', 'value' => 10, 'is_statutory' => true, 'statutory_authority' => 'GST Authority', 'remittance_mapping_key' => 'statutory_tax_payable', 'employer_calculation' => 'percentage', 'employer_value' => 5, 'employer_mapping_key' => 'statutory_employer_payable'])->assertCreated();
        $this->getJson('/api/hr/payroll-rules?cursor_mode=1')->assertOk()->assertJsonPath('meta.feed', 'hr.payroll-rules')->assertJsonCount(2, 'data');
        $expense = ChartOfAccount::create(['company_id' => $company->id, 'code' => '6100', 'name' => 'Payroll expense', 'account_type' => 'expense', 'is_active' => true]);
        $payable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2200', 'name' => 'Payroll payable', 'account_type' => 'liability', 'is_active' => true]);
        $deductions = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2210', 'name' => 'Payroll deductions payable', 'account_type' => 'liability', 'is_active' => true]);
        $statutory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2220', 'name' => 'Statutory tax payable', 'account_type' => 'liability', 'is_active' => true]);
        $employerStatutory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2230', 'name' => 'Employer statutory payable', 'account_type' => 'liability', 'is_active' => true]);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1010', 'name' => 'Payroll bank', 'account_type' => 'asset', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'payroll_expense', 'account_id' => $expense->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'payroll_payable', 'account_id' => $payable->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'payroll_deductions', 'account_id' => $deductions->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'statutory_tax_payable', 'account_id' => $statutory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'statutory_employer_payable', 'account_id' => $employerStatutory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cash_bank', 'account_id' => $cash->id]);
        FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $runPayload = ['run_no' => 'PAY-2026-09', 'external_reference' => 'payroll-2026-09', 'frequency' => 'monthly', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'pay_date' => '2026-10-01'];
        $run = $this->postJson('/api/hr/pay-runs', $runPayload)->assertCreated()->assertJsonCount(1, 'data.payslips');
        $this->getJson('/api/hr/pay-runs?cursor_mode=1')->assertOk()->assertJsonPath('meta.feed', 'hr.pay-runs')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('hr_payslips', ['pay_run_id' => $run->json('data.id'), 'employee_id' => $active->json('data.id'), 'gross_amount' => 26250, 'deduction_amount' => 2625, 'net_amount' => 23625]);
        $this->postJson('/api/hr/pay-runs', $runPayload)->assertOk()->assertJsonPath('status', 'existing');
        $approvedRun = $this->postJson('/api/hr/pay-runs/'.$run->json('data.id').'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($approvedRun->json('data.journal_entry_id'));
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $approvedRun->json('data.journal_entry_id'), 'account_id' => $statutory->id, 'credit' => 2625]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $approvedRun->json('data.journal_entry_id'), 'account_id' => $employerStatutory->id, 'credit' => 1312.5]);
        $paid = $this->postJson('/api/hr/pay-runs/'.$run->json('data.id').'/pay', ['paid_at' => '2026-10-01', 'payment_reference' => 'BANK-1'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_reference', 'BANK-1');
        $this->assertNotNull($paid->json('data.settlement_journal_entry_id'));
        $this->postJson('/api/hr/pay-runs/'.$run->json('data.id').'/pay', ['paid_at' => '2026-10-01', 'payment_reference' => 'BANK-REPLAY'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_reference', 'BANK-1');
        $reversed = $this->postJson('/api/hr/pay-runs/'.$run->json('data.id').'/reverse-payment', ['reason' => 'Bank file was rejected'])
            ->assertOk()->assertJsonPath('data.status', 'settlement_reversed')->assertJsonPath('status', 'settlement_reversed');
        $this->assertNotNull($reversed->json('data.settlement_reversal_journal_entry_id'));
        $this->postJson('/api/hr/pay-runs/'.$run->json('data.id').'/reverse-payment', ['reason' => 'Duplicate'])->assertStatus(422);
        $weeklyRun = $this->postJson('/api/hr/pay-runs', ['run_no' => 'PAY-W-2026-09', 'frequency' => 'weekly', 'period_from' => '2026-09-14', 'period_to' => '2026-09-20', 'attendance_policy' => 'unpaid_absence', 'overtime_policy' => 'pay_overtime', 'overtime_multiplier' => 1.5])->assertCreated();
        $weeklyPayslip = HrPayslip::where('pay_run_id', $weeklyRun->json('data.id'))->where('employee_id', $weekly->json('data.id'))->firstOrFail();
        $this->assertEqualsWithDelta(806.026786, (float) $weeklyPayslip->gross_amount, 0.000001);
        $this->assertEqualsWithDelta(205.642857, (float) $weeklyPayslip->deduction_amount, 0.000001);
        $this->assertEqualsWithDelta(600.383929, (float) $weeklyPayslip->net_amount, 0.000001);
        $this->assertArrayHasKey('benefit_'.$meal->json('data.id'), $weeklyPayslip->deductions);
        $this->assertArrayHasKey('benefit_'.$insurance->json('data.id'), $weeklyPayslip->deductions);
        $this->postJson('/api/hr/employees/'.$weekly->json('data.id').'/benefits/'.$insurance->json('data.id').'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
    }
}
