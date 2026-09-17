<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrPayrollRule extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['value' => 'decimal:6', 'employer_value' => 'decimal:6', 'effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean', 'is_statutory' => 'boolean'];
}
