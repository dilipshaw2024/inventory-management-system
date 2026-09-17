<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ServiceTechnician extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['skills' => 'array', 'hourly_rate' => 'decimal:6', 'is_available' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }
}
