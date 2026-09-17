<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierPaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:6', 'payment_amount' => 'decimal:6', 'exchange_rate' => 'decimal:12', 'allocated_at' => 'datetime', 'voided_at' => 'datetime'];
    public function payment() { return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id'); }
    public function invoice() { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function voider() { return $this->belongsTo(User::class, 'voided_by'); }
}
