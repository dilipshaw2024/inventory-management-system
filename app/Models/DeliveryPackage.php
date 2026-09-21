<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeliveryPackage extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'weight' => 'decimal:6', 'length' => 'decimal:6', 'width' => 'decimal:6', 'height' => 'decimal:6',
        'packed_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime',
    ];

    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function lines() { return $this->hasMany(DeliveryPackageLine::class, 'package_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
