<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTaxRegistration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
