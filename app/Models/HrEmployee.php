<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrEmployee extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['hire_date' => 'date', 'termination_date' => 'date', 'basic_salary' => 'decimal:6'];

    public function user() { return $this->belongsTo(User::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function benefits() { return $this->hasMany(HrEmployeeBenefit::class, 'employee_id'); }
}
