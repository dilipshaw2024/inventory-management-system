<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrLeaveRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'decimal:3', 'approved_at' => 'datetime'];
    public function employee() { return $this->belongsTo(HrEmployee::class); }
    public function leaveType() { return $this->belongsTo(HrLeaveType::class, 'leave_type_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
