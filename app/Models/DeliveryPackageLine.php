<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPackageLine extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:6'];

    public function package() { return $this->belongsTo(DeliveryPackage::class, 'package_id'); }
    public function deliveryLine() { return $this->belongsTo(DeliveryLine::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
