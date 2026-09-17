<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountLine extends Model
{
    protected $guarded = [];
    protected $casts = ['system_quantity' => 'decimal:6', 'counted_quantity' => 'decimal:6', 'recounted_quantity' => 'decimal:6', 'variance_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'recounted_at' => 'datetime'];
    public function stockCount() { return $this->belongsTo(StockCount::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function recounter() { return $this->belongsTo(User::class, 'recounted_by'); }
}
