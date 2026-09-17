<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function lines() { return $this->hasMany(JournalLine::class); }
    public function budgets() { return $this->hasMany(CostCenterBudget::class); }
}
