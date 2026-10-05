<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrLeaveType extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['annual_entitlement' => 'decimal:3', 'carry_forward_days' => 'decimal:3', 'accrual_start_month' => 'integer', 'is_paid' => 'boolean', 'counts_working_days' => 'boolean', 'is_active' => 'boolean'];
    public function requests() { return $this->hasMany(HrLeaveRequest::class, 'leave_type_id'); }
}
