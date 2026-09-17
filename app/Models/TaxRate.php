<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class TaxRate extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['rate' => 'decimal:4', 'is_active' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
}
