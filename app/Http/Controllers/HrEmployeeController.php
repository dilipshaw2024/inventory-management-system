<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\HrEmployee;
use App\Models\HrEmployeeBenefit;
use App\Models\HrPayRun;
use App\Models\HrPayrollRule;
use App\Services\HrPayrollService;
use App\Services\HrPayrollAccountingService;
use App\Models\User;
use App\Services\HrPayrollSettlementService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrEmployeeController extends Controller
{
    private function sameCompany(string $table)
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id));
    }

    public function index()
    {
        $companyId = auth()->user()?->company_id;
        $employees = HrEmployee::with(['branch', 'department', 'user', 'benefits'])->where('company_id', $companyId)->orderBy('employee_no')->paginate(50);
        $branches = Branch::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $departments = Department::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $users = User::where('company_id', $companyId)->where('is_active', true)->whereDoesntHave('hrEmployee')->orderBy('name')->get();
        return view('admin.erp.hr_employees', compact('employees', 'branches', 'departments', 'users'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate([
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('hr_employees', 'employee_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'branch_id' => ['nullable', 'integer', $this->sameCompany('branches')],
            'department_id' => ['nullable', 'integer', $this->sameCompany('departments')],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'job_title' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['required', 'in:full_time,part_time,contract,temporary'], 'hire_date' => ['nullable', 'date'], 'pay_frequency' => ['required', 'in:weekly,biweekly,monthly'],
            'basic_salary' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'],
        ]);
        $data['currency_code'] = isset($data['currency_code']) ? strtoupper($data['currency_code']) : null;
        $employee = HrEmployee::create($data + ['company_id' => $companyId, 'status' => 'active']);
        app(AuditService::class)->record('hr_employee.created', $employee, null, $employee->toArray());
        return back()->with(['message' => 'Employee created.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $employee = HrEmployee::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if ($employee->status !== 'terminated') {
            $old = $employee->toArray();
            $employee->update(['status' => 'terminated', 'termination_date' => now()->toDateString()]);
            app(AuditService::class)->record('hr_employee.terminated', $employee, $old, $employee->toArray());
        }
        return back()->with(['message' => 'Employee marked as terminated.', 'alert-type' => 'success']);
    }

    public function storeBenefit(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_employee_benefits', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('employee_id', $employee->id))],
            'name' => ['required', 'string', 'max:150'], 'benefit_type' => ['required', 'in:earning,deduction'], 'calculation' => ['required', 'in:fixed,percentage'], 'value' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $benefit = HrEmployeeBenefit::create($data + ['company_id' => $companyId, 'employee_id' => $employee->id, 'is_active' => true]);
        app(AuditService::class)->record('hr_employee_benefit.created', $benefit, null, $benefit->toArray());
        return back()->with(['message' => 'Employee benefit added.', 'alert-type' => 'success']);
    }

    public function deactivateBenefit(int $id, int $benefitId)
    {
        $companyId = auth()->user()?->company_id;
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($id);
        $benefit = HrEmployeeBenefit::where('company_id', $companyId)->where('employee_id', $employee->id)->findOrFail($benefitId);
        $old = $benefit->toArray(); $benefit->update(['is_active' => false]);
        app(AuditService::class)->record('hr_employee_benefit.deactivated', $benefit, $old, $benefit->toArray());
        return back()->with(['message' => 'Employee benefit deactivated.', 'alert-type' => 'success']);
    }

    public function payRuns()
    {
        $companyId = auth()->user()?->company_id;
        $payRuns = HrPayRun::withCount('payslips')->where('company_id', $companyId)->latest('period_to')->paginate(50);
        $rules = HrPayrollRule::where('company_id', $companyId)->orderBy('code')->get();
        return view('admin.erp.hr_pay_runs', compact('payRuns', 'rules'));
    }

    public function storePayrollRule(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_payroll_rules', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:150'], 'rule_type' => ['required', 'in:earning,deduction'], 'calculation' => ['required', 'in:fixed,percentage'], 'value' => ['required', 'numeric', 'min:0'], 'is_statutory' => ['nullable', 'boolean'], 'statutory_authority' => ['nullable', 'string', 'max:120'], 'remittance_mapping_key' => ['nullable', 'string', 'max:80', 'alpha_dash'], 'employer_calculation' => ['nullable', 'in:fixed,percentage'], 'employer_value' => ['nullable', 'numeric', 'min:0'], 'employer_mapping_key' => ['nullable', 'string', 'max:80', 'alpha_dash'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from']]);
        $rule = HrPayrollRule::create($data + ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('hr_payroll_rule.created', $rule, null, $rule->toArray());
        return back()->with(['message' => 'Payroll rule created.', 'alert-type' => 'success']);
    }

    public function deactivatePayrollRule(int $id)
    {
        $rule = HrPayrollRule::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        $old = $rule->toArray(); $rule->update(['is_active' => false]);
        app(AuditService::class)->record('hr_payroll_rule.deactivated', $rule, $old, $rule->toArray());
        return back()->with(['message' => 'Payroll rule deactivated.', 'alert-type' => 'success']);
    }

    public function storePayRun(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['run_no' => ['required', 'string', 'max:60', Rule::unique('hr_pay_runs', 'run_no')->where(fn ($query) => $query->where('company_id', $companyId))], 'frequency' => ['required', 'in:weekly,biweekly,monthly'], 'period_from' => ['required', 'date'], 'period_to' => ['required', 'date', 'after_or_equal:period_from'], 'pay_date' => ['nullable', 'date', 'after_or_equal:period_to'], 'attendance_policy' => ['nullable', 'in:ignore,unpaid_absence'], 'overtime_policy' => ['nullable', 'in:ignore,pay_overtime'], 'overtime_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5']]);
        if (HrPayRun::where('company_id', $companyId)->where('frequency', $data['frequency'])->whereIn('status', ['draft', 'approved', 'paid'])->where('period_from', '<=', $data['period_to'])->where('period_to', '>=', $data['period_from'])->exists()) return back()->withErrors(['period_from' => 'The payroll period overlaps an existing non-cancelled run.'])->withInput();
        $run = HrPayRun::create($data + ['company_id' => $companyId, 'status' => 'draft', 'created_by' => auth()->id()]);
        app(HrPayrollService::class)->generate($run);
        app(AuditService::class)->record('hr_pay_run.created', $run, null, $run->toArray());
        return back()->with(['message' => 'Pay run generated with '.$run->payslips()->count().' payslips.', 'alert-type' => 'success']);
    }

    public function approvePayRun(int $id)
    {
        $run = HrPayRun::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if ($run->status !== 'draft') return back()->withErrors(['pay_run' => 'Only draft pay runs can be approved.']);
        if (!$run->payslips()->exists()) return back()->withErrors(['pay_run' => 'A pay run must contain at least one payslip.']);
        $old = $run->toArray();
        $run->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        try {
            app(HrPayrollAccountingService::class)->post($run);
        } catch (\RuntimeException $exception) {
            $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
            return back()->withErrors(['pay_run' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('hr_pay_run.approved', $run, $old, $run->toArray());
        return back()->with(['message' => 'Pay run approved.', 'alert-type' => 'success']);
    }

    public function payPayRun(Request $request, int $id)
    {
        $run = HrPayRun::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        $data = $request->validate(['paid_at' => ['required', 'date'], 'payment_reference' => ['nullable', 'string', 'max:150']]);
        $old = $run->toArray();
        try {
            $run = app(HrPayrollSettlementService::class)->settle($run, $data['paid_at'], $data['payment_reference'] ?? null);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['pay_run' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('hr_pay_run.paid', $run, $old, $run->toArray());
        return back()->with(['message' => 'Pay run settled.', 'alert-type' => 'success']);
    }
}
