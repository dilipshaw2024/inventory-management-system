<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotationLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount_amount' => 'decimal:6'];
    public function quotation() { return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
