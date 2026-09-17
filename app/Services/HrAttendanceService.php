<?php

namespace App\Services;

use App\Models\HrAttendance;
use App\Models\HrEmployee;
use Carbon\Carbon;

class HrAttendanceService
{
    public function record(int $companyId, array $data, ?int $userId = null): HrAttendance
    {
        $employee = HrEmployee::where('company_id', $companyId)->findOrFail($data['employee_id']);
        $date = Carbon::parse($data['attendance_date'])->toDateString();
        $checkIn = $data['check_in'] ?? null; $checkOut = $data['check_out'] ?? null;
        $minutes = (int) ($data['worked_minutes'] ?? 0);
        if ($checkIn && $checkOut) {
            $start = Carbon::parse($date.' '.$checkIn); $end = Carbon::parse($date.' '.$checkOut);
            if ($end->lessThan($start)) $end->addDay();
            $minutes = $start->diffInMinutes($end);
        }
        if ($data['status'] !== 'present') $minutes = 0;
        $scheduled = isset($data['scheduled_minutes']) ? (int) $data['scheduled_minutes'] : null;
        $overtime = $scheduled && $data['status'] === 'present' ? max(0, $minutes - $scheduled) : 0;
        return HrAttendance::updateOrCreate(
            ['employee_id' => $employee->id, 'attendance_date' => $date],
            ['company_id' => $companyId, 'status' => $data['status'], 'check_in' => $checkIn, 'check_out' => $checkOut, 'worked_minutes' => $minutes, 'scheduled_minutes' => $scheduled, 'overtime_minutes' => $overtime, 'note' => $data['note'] ?? null, 'recorded_by' => $userId]
        );
    }
}
