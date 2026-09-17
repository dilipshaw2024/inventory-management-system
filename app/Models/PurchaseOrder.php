<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'expected_date' => 'date', 'exchange_rate' => 'decimal:12', 'receiving_closed' => 'boolean', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function lines() { return $this->hasMany(PurchaseOrderLine::class); }
    public function receipts() { return $this->hasMany(GoodsReceipt::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
