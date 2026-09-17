<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['transaction_date' => 'date', 'amount' => 'decimal:6', 'raw_payload' => 'array', 'matched_at' => 'datetime', 'unmatched_at' => 'datetime', 'settled_at' => 'datetime', 'settlement_reversed_at' => 'datetime'];
    public function bankAccount() { return $this->belongsTo(BankAccount::class); }
    public function importBatch() { return $this->belongsTo(BankStatementImportBatch::class, 'import_batch_id'); }
    public function matcher() { return $this->belongsTo(User::class, 'matched_by'); }
    public function settlementJournal() { return $this->belongsTo(JournalEntry::class, 'settlement_journal_id'); }
    public function settlementAccount() { return $this->belongsTo(ChartOfAccount::class, 'settlement_account_id'); }
    public function settlementReversalJournal() { return $this->belongsTo(JournalEntry::class, 'settlement_reversal_journal_id'); }
}
