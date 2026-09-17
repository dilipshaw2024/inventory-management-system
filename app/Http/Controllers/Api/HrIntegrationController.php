<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrEmployeeBenefit;
use App\Models\HrPayRun;
use App\Models\HrPayrollRule;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Models\HrAttendance;
use App\Services\AuditService;
use App\Services\HrPayrollService;
use App\Services\HrLeaveService;
use App\Services\HrAttendanceService;
use App\Services\HrPayrollAccountingService;
use App\Services\HrPayrollSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrIntegrationController extends Controller
{
    private function companyId(Request $request): int
    {
        $id = (int) ($request->user()?->company_id ?? 0);
        abort_unless($id, 403, 'A company is required for HR operations.');
        return $id;
    }

    public function employees(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['status' => ['nullable', 'in:active,on_leave,terminated'], 'department_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = HrEmployee::with(['branch', 'department', 'user'])->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1)); $total = (clone $query)->count();
        return response()->json(['data' => $query->forPage($page, $perPage)->get(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('hr_employees', 'employee_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'job_title' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['required', 'in:full_time,part_time,contract,temporary'], 'hire_date' => ['nullable', 'date'], 'pay_frequency' => ['required', 'in:weekly,biweekly,monthly'], 'basic_salary' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'],
        ]);
        $data['currency_code'] = isset($data['currency_code']) ? strtoupper($data['currency_code']) : null;
        $employee = HrEmployee::create($data + ['company_id' => $companyId, 'status' => 'active']);
        app(AuditService::class)->record('hr_employee.created', $employee, null, $employee->toArray());
        return response()->json(['data' => $employee, 'status' => 'created'], 201);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($id);
        $old = $employee->toArray();
        $employee->update(['status' => 'terminated', 'termination_date' => now()->toDateString()]);
        app(AuditService::class)->record('hr_employee.terminated', $employee, $old, $employee->toArray());
        return response()->json(['data' => $employee->fresh(), 'status' => 'terminated']);
    }

    public function employeeBenefits(Request $request, int $id): JsonResponse
    {
        $employee = HrEmployee::where('company_id', $this->companyId($request))->findOrFail($id);
        $benefits = HrEmployeeBenefit::where('company_id', $employee->company_id)->where('employee_id', $employee->id)->orderBy('code')->get();
        return response()->json(['data' => $benefits, 'meta' => ['total' => $benefits->count()]]);
    }

    public function storeEmployeeBenefit(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_employee_benefits', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('employee_id', $employee->id))],
            'name' => ['required', 'string', 'max:150'], 'benefit_type' => ['required', 'in:earning,deduction'], 'calculation' => ['required', 'in:fixed,percentage'], 'value' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $benefit = HrEmployeeBenefit::create($data + ['company_id' => $companyId, 'employee_id' => $employee->id, 'is_active' => true]);
        app(AuditService::class)->record('hr_employee_benefit.created', $benefit, null, $benefit->toArray());
        return response()->json(['data' => $benefit, 'status' => 'created'], 201);
    }

    public function deactivateEmployeeBenefit(Request $request, int $id, int $benefitId): JsonResponse
    {
        $employee = HrEmployee::where('company_id', $this->companyId($request))->findOrFail($id);
        $benefit = HrEmployeeBenefit::where('company_id', $employee->company_id)->where('employee_id', $employee->id)->findOrFail($benefitId);
        $old = $benefit->toArray(); $benefit->update(['is_active' => false]);
        app(AuditService::class)->record('hr_employee_benefit.deactivated', $benefit, $old, $benefit->toArray());
        return response()->json(['data' => $benefit->fresh(), 'status' => 'deactivated']);
    }

    public function payRuns(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $runs = HrPayRun::withCount('payslips')->where('company_id', $companyId)->orderByDesc('period_to')->orderByDesc('id')->get();
        return response()->json(['data' => $runs, 'meta' => ['total' => $runs->count()]]);
    }

    public function payrollRules(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $rules = HrPayrollRule::where('company_id', $companyId)->orderBy('code')->get();
        return response()->json(['data' => $rules, 'meta' => ['total' => $rules->count()]]);
    }

    public function storePayrollRule(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_payroll_rules', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:150'], 'rule_type' => ['required', 'in:earning,deduction'], 'calculation' => ['required', 'in:fixed,percentage'], 'value' => ['required', 'numeric', 'min:0'], 'is_statutory' => ['nullable', 'boolean'], 'statutory_authority' => ['nullable', 'string', 'max:120'], 'remittance_mapping_key' => ['nullable', 'string', 'max:80', 'alpha_dash'], 'employer_calculation' => ['nullable', 'in:fixed,percentage'], 'employer_value' => ['nullable', 'numeric', 'min:0'], 'employer_mapping_key' => ['nullable', 'string', 'max:80', 'alpha_dash'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from']]);
        $rule = HrPayrollRule::create($data + ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('hr_payroll_rule.created', $rule, null, $rule->toArray());
        return response()->json(['data' => $rule, 'status' => 'created'], 201);
    }

    public function deactivatePayrollRule(Request $request, int $id): JsonResponse
    {
        $rule = HrPayrollRule::where('company_id', $this->companyId($request))->findOrFail($id);
        $old = $rule->toArray(); $rule->update(['is_active' => false]);
        app(AuditService::class)->record('hr_payroll_rule.deactivated', $rule, $old, $rule->toArray());
        return response()->json(['data' => $rule->fresh(), 'status' => 'deactivated']);
    }

    public function storePayRun(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if ($request->filled('external_reference')) {
            $existing = HrPayRun::with('payslips.employee')->where('company_id', $companyId)->where('external_reference', $request->input('external_reference'))->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'existing']);
        }
        $data = $request->validate(['run_no' => ['required', 'string', 'max:60', Rule::unique('hr_pay_runs', 'run_no')->where(fn ($query) => $query->where('company_id', $companyId))], 'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('hr_pay_runs', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))], 'frequency' => ['required', 'in:weekly,biweekly,monthly'], 'period_from' => ['required', 'date'], 'period_to' => ['required', 'date', 'after_or_equal:period_from'], 'pay_date' => ['nullable', 'date', 'after_or_equal:period_to'], 'attendance_policy' => ['nullable', 'in:ignore,unpaid_absence'], 'overtime_policy' => ['nullable', 'in:ignore,pay_overtime'], 'overtime_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5']]);
        if (HrPayRun::where('company_id', $companyId)->where('frequency', $data['frequency'])->whereIn('status', ['draft', 'approved', 'paid'])->where('period_from', '<=', $data['period_to'])->where('period_to', '>=', $data['period_from'])->exists()) abort(422, 'The payroll period overlaps an existing non-cancelled run.');
        $run = HrPayRun::create($data + ['company_id' => $companyId, 'status' => 'draft', 'created_by' => $request->user()->id]);
        $run = app(HrPayrollService::class)->generate($run);
        app(AuditService::class)->record('hr_pay_run.created', $run, null, $run->toArray());
        return response()->json(['data' => $run, 'status' => 'created'], 201);
    }

    public function approvePayRun(Request $request, int $id): JsonResponse
    {
        $run = HrPayRun::where('company_id', $this->companyId($request))->findOrFail($id);
        if ($run->status !== 'draft') abort(422, 'Only draft pay runs can be approved.');
        if (!$run->payslips()->exists()) abort(422, 'A pay run must contain at least one payslip.');
        $old = $run->toArray();
        $run->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        try {
            $run = app(HrPayrollAccountingService::class)->post($run);
        } catch (\RuntimeException $exception) {
            $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
            abort(422, $exception->getMessage());
        }
        app(AuditService::class)->record('hr_pay_run.approved', $run, $old, $run->toArray());
        return response()->json(['data' => $run->fresh('payslips.employee'), 'status' => 'approved']);
    }

    public function payPayRun(Request $request, int $id): JsonResponse
    {
        $run = HrPayRun::where('company_id', $this->companyId($request))->findOrFail($id);
        $data = $request->validate(['paid_at' => ['required', 'date'], 'payment_reference' => ['nullable', 'string', 'max:150']]);
        $old = $run->toArray();
        try {
            $run = app(HrPayrollSettlementService::class)->settle($run, $data['paid_at'], $data['payment_reference'] ?? null);
        } catch (\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }
        app(AuditService::class)->record('hr_pay_run.paid', $run, $old, $run->toArray());
        return response()->json(['data' => $run, 'status' => 'paid']);
    }

    public function leaveTypes(Request $request): JsonResponse
    {
        $types = HrLeaveType::where('company_id', $this->companyId($request))->orderBy('code')->get();
        return response()->json(['data' => $types, 'meta' => ['total' => $types->count()]]);
    }

    public function storeLeaveType(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_leave_types', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:150'], 'annual_entitlement' => ['required', 'numeric', 'min:0'], 'is_paid' => ['nullable', 'boolean']]);
        $type = HrLeaveType::create($data + ['company_id' => $companyId, 'is_paid' => (bool) ($data['is_paid'] ?? true), 'is_active' => true]);
        app(AuditService::class)->record('hr_leave_type.created', $type, null, $type->toArray());
        return response()->json(['data' => $type, 'status' => 'created'], 201);
    }

    public function leaveRequests(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'employee_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date']]);
        $requests = HrLeaveRequest::with(['employee', 'leaveType', 'approver'])->where('company_id', $companyId)->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($data['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))->orderBy('updated_at')->orderBy('id')->get();
        return response()->json(['data' => $requests, 'meta' => ['total' => $requests->count()]]);
    }

    public function storeLeaveRequest(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['nullable', 'string', 'max:2000']]);
        try {
            $leave = app(HrLeaveService::class)->create($companyId, $data);
        } catch (\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }
        app(AuditService::class)->record('hr_leave_request.created', $leave, null, $leave->toArray());
        return response()->json(['data' => $leave->load(['employee', 'leaveType']), 'status' => 'created'], 201);
    }

    public function approveLeave(Request $request, int $id): JsonResponse
    {
        return $this->decideLeave($request, $id, 'approved');
    }

    public function rejectLeave(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision_reason' => ['required', 'string', 'max:2000']]);
        return $this->decideLeave($request, $id, 'rejected', $data['decision_reason']);
    }

    private function decideLeave(Request $request, int $id, string $status, ?string $reason = null): JsonResponse
    {
        $leave = HrLeaveRequest::where('company_id', $this->companyId($request))->findOrFail($id);
        if ($leave->status !== 'pending') abort(422, 'Only pending leave requests can be decided.');
        $old = $leave->toArray();
        $leave->update(['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $reason]);
        app(AuditService::class)->record('hr_leave_request.'.$status, $leave, $old, $leave->toArray());
        return response()->json(['data' => $leave->fresh(['employee', 'leaveType']), 'status' => $status]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'employee_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:present,absent,leave,holiday'], 'updated_since' => ['nullable', 'date']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $rows = HrAttendance::with('employee')->where('company_id', $companyId)->whereBetween('attendance_date', [$from, $to])->when($data['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))->orderBy('attendance_date')->orderBy('employee_id')->get();
        return response()->json(['data' => $rows, 'meta' => ['from' => $from, 'to' => $to, 'total' => $rows->count()]]);
    }

    public function recordAttendance(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'attendance_date' => ['required', 'date'], 'status' => ['required', 'in:present,absent,leave,holiday'], 'check_in' => ['nullable', 'date_format:H:i'], 'check_out' => ['nullable', 'date_format:H:i'], 'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'scheduled_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'note' => ['nullable', 'string', 'max:2000']]);
        $attendance = app(HrAttendanceService::class)->record($companyId, $data, $request->user()->id);
        app(AuditService::class)->record('hr_attendance.recorded', $attendance, null, $attendance->toArray());
        return response()->json(['data' => $attendance->load('employee'), 'status' => 'recorded']);
    }
}
