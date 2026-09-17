<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierClaim extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['claim_date' => 'date', 'claim_amount' => 'decimal:6', 'settled_amount' => 'decimal:6', 'settlement_posted_amount' => 'decimal:6', 'resolved_at' => 'datetime', 'settled_at' => 'datetime'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class); }
    public function inventoryReturn() { return $this->belongsTo(InventoryReturn::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
    public function settler() { return $this->belongsTo(User::class, 'settled_by'); }
    public function settlementJournal() { return $this->belongsTo(JournalEntry::class, 'settlement_journal_id'); }
    public function creditNotes() { return $this->hasMany(SupplierCreditNote::class, 'supplier_claim_id'); }
}
