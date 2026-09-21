<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $guarded = [];
    protected $casts = ['ordered_qty' => 'decimal:6', 'received_qty' => 'decimal:6', 'unit_price' => 'decimal:6'];
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function receiptLines() { return $this->hasMany(GoodsReceiptLine::class); }
}
