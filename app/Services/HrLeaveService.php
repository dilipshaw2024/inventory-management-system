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
    public function create(int $companyId, array $data): HrLeaveRequest
    {
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($data['employee_id']);
        $type = HrLeaveType::where('company_id', $companyId)->where('is_active', true)->findOrFail($data['leave_type_id']);
        $starts = Carbon::parse($data['starts_on']); $ends = Carbon::parse($data['ends_on']);
        $days = $starts->diffInDays($ends) + 1;
        if ($employee->status !== 'active') throw new RuntimeException('Leave can only be requested for an active employee.');
        if ($days <= 0) throw new RuntimeException('Leave dates are invalid.');
        $overlap = HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->where('starts_on', '<=', $ends->toDateString())->where('ends_on', '>=', $starts->toDateString())->exists();
        if ($overlap) throw new RuntimeException('The employee already has an overlapping pending or approved leave request.');
        $used = (float) HrLeaveRequest::where('company_id', $companyId)->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'approved')->whereYear('starts_on', $starts->year)->sum('days');
        if ($type->annual_entitlement > 0 && $used + $days > (float) $type->annual_entitlement) throw new RuntimeException('The leave request exceeds the annual entitlement.');
        return DB::transaction(fn (): HrLeaveRequest => HrLeaveRequest::create(['company_id' => $companyId, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(), 'days' => $days, 'reason' => $data['reason'] ?? null, 'status' => 'pending']));
    }
}
