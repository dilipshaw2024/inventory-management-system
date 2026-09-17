<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class WorkCenter extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['capacity_hours_per_day' => 'decimal:2', 'labor_rate' => 'decimal:6', 'machine_rate' => 'decimal:6', 'calendar' => 'array', 'is_active' => 'boolean'];
    public function operations() { return $this->hasMany(RoutingOperation::class); }
}
