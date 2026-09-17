<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'is_final_delivery' => 'boolean', 'inspected_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'discrepancy_resolved_at' => 'datetime'];
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function lines() { return $this->hasMany(GoodsReceiptLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function inspector() { return $this->belongsTo(User::class, 'inspected_by'); }
}
