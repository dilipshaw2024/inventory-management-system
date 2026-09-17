<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecurringJournalRun extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['run_date' => 'date'];

    public function template() { return $this->belongsTo(RecurringJournalTemplate::class, 'recurring_journal_template_id'); }
    public function journal() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
