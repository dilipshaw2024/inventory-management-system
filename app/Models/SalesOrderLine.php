<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $guarded = [];
    protected $casts = ['ordered_qty' => 'decimal:6', 'delivered_qty' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount_amount' => 'decimal:6'];
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class, 'batch_id'); }
    public function deliveryLines() { return $this->hasMany(DeliveryLine::class); }
}
