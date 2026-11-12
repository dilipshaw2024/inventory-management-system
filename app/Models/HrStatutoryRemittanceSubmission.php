<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrStatutoryRemittanceSubmission extends Model
{
    protected $table = 'hr_statutory_remittance_submissions';

    protected $fillable = [
        'company_id', 'provider', 'package_hash', 'filters', 'summary', 'status',
        'provider_reference', 'provider_response', 'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'filters' => 'array', 'summary' => 'array', 'provider_response' => 'array',
        'submitted_at' => 'datetime',
    ];
}
