<?php

namespace App\Services;

use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HrLeaveService
{
    public function balances(int $companyId, int $year, ?int $employeeId = null, ?int $leaveTypeId = null, ?Carbon $asOf = null): array
    {
        $employees = HrEmployee::where('company_id', $companyId)->when($employeeId, fn ($query, $id) => $query->whereKey($id))->orderBy('employee_no')->get();
        $types = HrLeaveType::where('company_id', $companyId)->when($leaveTypeId, fn ($query, $id) => $query->whereKey($id))->orderBy('code')->get();
        $rows = [];
        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $rows[] = $this->balance($companyId, (int) $employee->id, (int) $type->id, $year, $employee, $type, $asOf);
            }
        }
        return $rows;
    }

    public function balance(int $companyId, int $employeeId, int $leaveTypeId, int $year, ?HrEmployee $employee = null, ?HrLeaveType $type = null, ?Carbon $asOf = null): array
    {
        $employee ??= HrEmployee::where('company_id', $companyId)->findOrFail($employeeId);
        $type ??= HrLeaveType::where('company_id', $companyId)->findOrFail($leaveTypeId);
        $approved = (float) HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('status', 'approved')->whereYear('starts_on', $year)->sum('days');
        $pending = (float) HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('status', 'pending')->whereYear('starts_on', $year)->sum('days');
        $priorUsed = (float) HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('status', 'approved')->whereYear('starts_on', $year - 1)->sum('days');
        $annual = (float) $type->annual_entitlement;
        $asOf ??= Carbon::today();
        $periodsPerYear = match ($type->accrual_frequency ?: 'annual') {
            'monthly' => 12,
            'quarterly' => 4,
            default => 1,
        };
        $periodsElapsed = $this->accrualPeriodsElapsed($year, $type, $asOf, $periodsPerYear);
        $earned = $annual > 0 ? round($annual * ($periodsElapsed / $periodsPerYear), 3) : 0.0;
        $carry = $annual > 0 ? min((float) $type->carry_forward_days, max(0, $annual - $priorUsed)) : 0.0;
        $entitlement = $annual > 0 ? $earned + $carry : null;
        return [
            'employee_id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'employee_name' => trim($employee->first_name.' '.($employee->last_name ?? '')),
            'leave_type_id' => $type->id,
            'leave_code' => $type->code,
            'leave_type' => $type->name,
            'year' => $year,
            'counts_working_days' => (bool) $type->counts_working_days,
            'annual_entitlement' => $annual,
            'accrual_frequency' => $type->accrual_frequency ?: 'annual',
            'accrual_start_month' => (int) ($type->accrual_start_month ?: 1),
            'as_of' => $asOf->toDateString(),
            'accrual_periods_elapsed' => $periodsElapsed,
            'accrual_periods_per_year' => $periodsPerYear,
            'earned_entitlement' => $earned,
            'prior_year_approved_days' => round($priorUsed, 3),
            'carry_forward_days' => round($carry, 3),
            'entitlement' => $entitlement === null ? null : round($entitlement, 3),
            'approved_days' => round($approved, 3),
            'pending_days' => round($pending, 3),
            'available_days' => $entitlement === null ? null : round(max(0, $entitlement - $approved - $pending), 3),
        ];
    }

    public function create(int $companyId, array $data): HrLeaveRequest
    {
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($data['employee_id']);
        $type = HrLeaveType::where('company_id', $companyId)->where('is_active', true)->findOrFail($data['leave_type_id']);
        $starts = Carbon::parse($data['starts_on']); $ends = Carbon::parse($data['ends_on']);
        if ($starts->year !== $ends->year) throw new RuntimeException('Leave requests cannot span calendar years. Submit one request per year.');
        $days = $type->counts_working_days
            ? app(PlanningCalendarService::class)->countWorkingDays($starts->toDateString(), $ends->toDateString(), $companyId)
            : $starts->diffInDays($ends) + 1;
        if ($employee->status !== 'active') throw new RuntimeException('Leave can only be requested for an active employee.');
        if ($days <= 0) throw new RuntimeException($type->counts_working_days ? 'The leave range contains no working days.' : 'Leave dates are invalid.');
        $overlap = HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->where('starts_on', '<=', $ends->toDateString())->where('ends_on', '>=', $starts->toDateString())->exists();
        if ($overlap) throw new RuntimeException('The employee already has an overlapping pending or approved leave request.');
        $balance = $this->balance($companyId, (int) $employee->id, (int) $type->id, $starts->year, $employee, $type, $starts->copy());
        if ($balance['entitlement'] !== null && (float) $balance['approved_days'] + (float) $balance['pending_days'] + $days > (float) $balance['entitlement']) throw new RuntimeException('The leave request exceeds the annual entitlement including permitted carry-forward.');
        return DB::transaction(fn (): HrLeaveRequest => HrLeaveRequest::create(['company_id' => $companyId, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(), 'days' => $days, 'reason' => $data['reason'] ?? null, 'status' => 'pending']));
    }

    private function accrualPeriodsElapsed(int $year, HrLeaveType $type, Carbon $asOf, int $periodsPerYear): int
    {
        if ($type->accrual_frequency === null || $type->accrual_frequency === 'annual') {
            return $asOf->year < $year ? 1 : ($asOf->year > $year ? 0 : 1);
        }
        if ($asOf->year < $year) return 0;
        if ($asOf->year > $year) return $periodsPerYear;
        $startMonth = max(1, min(12, (int) ($type->accrual_start_month ?: 1)));
        if ($asOf->month < $startMonth) return 0;
        $months = $asOf->month - $startMonth + 1;
        return min($periodsPerYear, $type->accrual_frequency === 'quarterly' ? (int) ceil($months / 3) : $months);
    }
}
