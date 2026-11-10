<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxRateComponent extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:4',
        'sequence' => 'integer',
        'is_compound' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function taxRate()
    {
        return $this->belongsTo(TaxRate::class);
    }
}
