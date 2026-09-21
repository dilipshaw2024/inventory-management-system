<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryCostRevaluationRun extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['as_of_date' => 'date', 'total_variance' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'reversed_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function lines() { return $this->hasMany(InventoryCostRevaluationLine::class, 'revaluation_run_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejector() { return $this->belongsTo(User::class, 'rejected_by'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry() { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function reverser() { return $this->belongsTo(User::class, 'reversed_by'); }
}
