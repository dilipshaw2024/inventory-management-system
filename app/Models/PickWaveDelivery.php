<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickWaveDelivery extends Model
{
    protected $guarded = [];

    public function wave() { return $this->belongsTo(PickWave::class, 'pick_wave_id'); }
    public function delivery() { return $this->belongsTo(Delivery::class); }
}
