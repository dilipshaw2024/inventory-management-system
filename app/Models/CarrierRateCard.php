<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CarrierRateCard extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = [
        'min_weight_kg' => 'decimal:6',
        'max_weight_kg' => 'decimal:6',
        'base_amount' => 'decimal:6',
        'per_kg_amount' => 'decimal:6',
        'minimum_charge' => 'decimal:6',
        'fuel_surcharge_percent' => 'decimal:6',
        'billable_weight_increment_kg' => 'decimal:6',
        'dimensional_divisor' => 'decimal:6',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];
}
