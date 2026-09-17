<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    protected $guarded = [];
    protected $casts = ['received_qty' => 'decimal:6', 'uom_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6'];
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class); }
    public function purchaseOrderLine() { return $this->belongsTo(PurchaseOrderLine::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function uom() { return $this->belongsTo(Unit::class, 'uom_id'); }
    public function batch() { return $this->belongsTo(InventoryBatch::class, 'batch_id'); }
}
