<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class DataRetentionHold extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['released_at' => 'datetime'];
    public function placer() { return $this->belongsTo(User::class, 'placed_by'); }
    public function releaser() { return $this->belongsTo(User::class, 'released_by'); }
}
