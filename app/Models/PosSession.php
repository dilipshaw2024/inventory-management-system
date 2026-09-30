<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:6',
        'expected_cash' => 'decimal:6',
        'closing_cash' => 'decimal:6',
        'variance' => 'decimal:6',
        'variance_journal_id' => 'integer',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function register() { return $this->belongsTo(PosRegister::class, 'register_id'); }
    public function opener() { return $this->belongsTo(User::class, 'opened_by'); }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }
    public function cashMovements() { return $this->hasMany(PosCashMovement::class, 'session_id'); }
    public function payments() { return $this->hasMany(Payment::class, 'pos_session_id'); }
    public function varianceJournal() { return $this->belongsTo(JournalEntry::class, 'variance_journal_id'); }
}
