<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function stockCount() { return $this->belongsTo(StockCount::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function assigner() { return $this->belongsTo(User::class, 'assigned_by'); }
}
