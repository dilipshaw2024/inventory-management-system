<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ServiceMaintenanceSchedule extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['next_due' => 'date', 'last_generated_at' => 'datetime', 'is_active' => 'boolean', 'meter_interval' => 'decimal:6', 'next_meter_due' => 'decimal:6'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
