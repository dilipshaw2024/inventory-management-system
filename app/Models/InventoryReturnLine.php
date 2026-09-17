<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReturnLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'unit_price' => 'decimal:6', 'tax_rate' => 'decimal:4'];
    public function inventoryReturn() { return $this->belongsTo(InventoryReturn::class, 'return_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
}
