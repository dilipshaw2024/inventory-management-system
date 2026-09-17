<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryOperation extends Model
{
    protected $guarded = [];
    protected $casts = ['completed_at' => 'datetime', 'confirmed_quantities' => 'array'];
    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function performer() { return $this->belongsTo(User::class, 'performed_by'); }
}
