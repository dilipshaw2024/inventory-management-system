<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class WarrantyClaim extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['received_at' => 'date', 'covered' => 'boolean', 'resolved_at' => 'datetime', 'settlement_amount' => 'decimal:6', 'settled_at' => 'datetime'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function contract() { return $this->belongsTo(ServiceContract::class, 'contract_id'); }
    public function settler() { return $this->belongsTo(User::class, 'settled_by'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
