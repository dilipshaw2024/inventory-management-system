<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrAttendance extends Model
{
    use BelongsToCompany;

    protected $table = 'hr_attendance';
    protected $guarded = [];
    protected $casts = ['attendance_date' => 'date', 'worked_minutes' => 'integer', 'scheduled_minutes' => 'integer', 'overtime_minutes' => 'integer'];
    public function employee() { return $this->belongsTo(HrEmployee::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
