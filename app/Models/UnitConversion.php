<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class UnitConversion extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['factor' => 'decimal:12', 'effective_from' => 'date', 'effective_to' => 'date', 'decimal_places' => 'integer', 'is_active' => 'boolean'];
    public function fromUnit() { return $this->belongsTo(Unit::class, 'from_unit_id'); }
    public function toUnit() { return $this->belongsTo(Unit::class, 'to_unit_id'); }
}
