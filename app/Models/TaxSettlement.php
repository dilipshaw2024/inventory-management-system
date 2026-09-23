<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxSettlement extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'paid_at' => 'date', 'net_tax' => 'decimal:6', 'reversed_at' => 'datetime'];
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function reversalJournalEntry() { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function reverser() { return $this->belongsTo(User::class, 'reversed_by'); }
}
