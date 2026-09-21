<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EInvoiceSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'provider_response' => 'array',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
