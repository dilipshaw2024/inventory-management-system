<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function fiscalYear() { return $this->belongsTo(FiscalYear::class); }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }
    public function inventorySnapshot() { return $this->belongsTo(InventoryReconciliationSnapshot::class, 'inventory_snapshot_id'); }
}
