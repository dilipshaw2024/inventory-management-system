<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PickWave extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['wave_date' => 'date', 'released_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function deliveries() { return $this->belongsToMany(Delivery::class, 'pick_wave_deliveries'); }
    public function deliveryLinks() { return $this->hasMany(PickWaveDelivery::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function releaser() { return $this->belongsTo(User::class, 'released_by'); }
    public function canceller() { return $this->belongsTo(User::class, 'cancelled_by'); }
}
