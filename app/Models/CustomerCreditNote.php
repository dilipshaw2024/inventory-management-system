<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CustomerCreditNote extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['credit_date' => 'date', 'subtotal_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
