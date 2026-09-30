<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashMovement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
        'occurred_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function session() { return $this->belongsTo(PosSession::class, 'session_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
