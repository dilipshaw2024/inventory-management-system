<?php

namespace App\Http\Controllers;

use App\Models\HrAttendance;
use App\Models\HrEmployee;
use App\Services\AuditService;
use App\Services\HrAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrAttendanceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $attendance = HrAttendance::with('employee')->where('company_id', $companyId)->whereBetween('attendance_date', [$from, $to])->latest('attendance_date')->paginate(50)->withQueryString();
        $employees = HrEmployee::where('company_id', $companyId)->where('status', 'active')->orderBy('employee_no')->get();
        return view('admin.erp.hr_attendance', compact('attendance', 'employees', 'from', 'to'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'attendance_date' => ['required', 'date'], 'status' => ['required', 'in:present,absent,leave,holiday'], 'check_in' => ['nullable', 'date_format:H:i'], 'check_out' => ['nullable', 'date_format:H:i'], 'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'scheduled_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'note' => ['nullable', 'string', 'max:2000']]);
        $attendance = app(HrAttendanceService::class)->record($companyId, $data, auth()->id());
        app(AuditService::class)->record('hr_attendance.recorded', $attendance, null, $attendance->toArray());
        return back()->with(['message' => 'Attendance recorded.', 'alert-type' => 'success']);
    }
}
