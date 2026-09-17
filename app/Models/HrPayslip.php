<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrPayslip extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['gross_amount' => 'decimal:6', 'deduction_amount' => 'decimal:6', 'net_amount' => 'decimal:6', 'attendance_deduction' => 'decimal:6', 'overtime_amount' => 'decimal:6', 'employer_contribution_total' => 'decimal:6', 'deductions' => 'array', 'statutory_deductions' => 'array', 'employer_contributions' => 'array'];
    public function payRun() { return $this->belongsTo(HrPayRun::class, 'pay_run_id'); }
    public function employee() { return $this->belongsTo(HrEmployee::class, 'employee_id'); }
}
