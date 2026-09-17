<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxFiling extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'sales_tax' => 'decimal:6', 'purchase_tax' => 'decimal:6', 'net_tax' => 'decimal:6', 'snapshot_payload' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
}
