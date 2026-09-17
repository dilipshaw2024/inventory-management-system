<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRfqLine extends Model
{
    protected $guarded = [];
    protected $casts = ['requested_qty' => 'decimal:6'];
    public function rfq() { return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function quotations() { return $this->hasMany(PurchaseSupplierQuotation::class, 'purchase_rfq_line_id'); }
}
