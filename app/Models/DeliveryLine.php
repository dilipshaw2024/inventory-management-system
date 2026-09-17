<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryLine extends Model
{
    protected $guarded = [];
    protected $casts = ['delivered_qty' => 'decimal:6', 'uom_quantity' => 'decimal:6', 'unit_price' => 'decimal:6'];
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function salesOrderLine() { return $this->belongsTo(SalesOrderLine::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function uom() { return $this->belongsTo(Unit::class, 'uom_id'); }
}
