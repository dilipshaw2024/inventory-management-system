<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:6', 'exchange_rate' => 'decimal:12', 'base_amount' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'is_reversed' => 'boolean', 'reversed_at' => 'datetime'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function allocations() { return $this->hasMany(SupplierPaymentAllocation::class)->whereNull('voided_at'); }
    public function allAllocations() { return $this->hasMany(SupplierPaymentAllocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
