<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReplenishmentScenario extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'horizon_days' => 'integer',
        'demand_multiplier' => 'decimal:6',
        'daily_demand_override' => 'decimal:6',
        'result_snapshot' => 'array',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
