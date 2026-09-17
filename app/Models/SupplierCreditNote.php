<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierCreditNote extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['credit_date' => 'date', 'subtotal_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function claim() { return $this->belongsTo(SupplierClaim::class, 'supplier_claim_id'); }
    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
