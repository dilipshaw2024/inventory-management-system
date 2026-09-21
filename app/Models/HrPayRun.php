<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrPayRun extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'pay_date' => 'date', 'paid_at' => 'date', 'settlement_reversed_at' => 'datetime', 'gross_total' => 'decimal:6', 'deduction_total' => 'decimal:6', 'employer_contribution_total' => 'decimal:6', 'net_total' => 'decimal:6', 'overtime_multiplier' => 'decimal:4', 'approved_at' => 'datetime'];
    public function payslips() { return $this->hasMany(HrPayslip::class, 'pay_run_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function journal() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function settlementJournal() { return $this->belongsTo(JournalEntry::class, 'settlement_journal_entry_id'); }
    public function settlementReversalJournal() { return $this->belongsTo(JournalEntry::class, 'settlement_reversal_journal_entry_id'); }
}
