<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_activity' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
