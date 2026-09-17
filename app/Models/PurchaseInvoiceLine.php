<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToProductCompany;

class PurchaseInvoiceLine extends Model
{
    use BelongsToProductCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:6', 'line_total' => 'decimal:6'];
    public function invoice() { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function purchaseOrderLine() { return $this->belongsTo(PurchaseOrderLine::class); }
    public function goodsReceiptLine() { return $this->belongsTo(GoodsReceiptLine::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
