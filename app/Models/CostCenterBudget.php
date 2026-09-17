<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CostCenterBudget extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'budget_amount' => 'decimal:6'];

    public function costCenter() { return $this->belongsTo(CostCenter::class); }
    public function fiscalYear() { return $this->belongsTo(FiscalYear::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
