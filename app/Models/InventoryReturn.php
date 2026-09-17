<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryReturn extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'tax_exempt' => 'boolean', 'inspection_required' => 'boolean', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'inspected_at' => 'datetime'];
    public function lines() { return $this->hasMany(InventoryReturnLine::class, 'return_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function sourceInvoice() { return $this->belongsTo(Invoice::class, 'source_invoice_id'); }
    public function sourceGoodsReceipt() { return $this->belongsTo(GoodsReceipt::class, 'source_goods_receipt_id'); }
    public function sourceDelivery() { return $this->belongsTo(Delivery::class, 'source_delivery_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function refunds() { return $this->hasMany(CustomerRefund::class, 'inventory_return_id'); }
    public function inspector() { return $this->belongsTo(User::class, 'inspected_by'); }
}
