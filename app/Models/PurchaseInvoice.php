<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoice extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date', 'subtotal_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'exchange_rate' => 'decimal:12', 'tax_exempt' => 'boolean', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function lines() { return $this->hasMany(PurchaseInvoiceLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
