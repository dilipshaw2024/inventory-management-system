<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionLine extends Model
{
    protected $guarded = [];
    protected $casts = ['requested_qty' => 'decimal:6', 'estimated_unit_price' => 'decimal:6'];
    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
