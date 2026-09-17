<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseSupplierQuotation extends Model
{
    protected $guarded = [];
    protected $casts = ['unit_price' => 'decimal:6', 'valid_until' => 'date'];
    public function rfqSupplier() { return $this->belongsTo(PurchaseRfqSupplier::class, 'purchase_rfq_supplier_id'); }
    public function line() { return $this->belongsTo(PurchaseRfqLine::class, 'purchase_rfq_line_id'); }
}
