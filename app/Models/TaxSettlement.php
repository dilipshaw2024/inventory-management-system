<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxSettlement extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'paid_at' => 'date', 'net_tax' => 'decimal:6'];
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
