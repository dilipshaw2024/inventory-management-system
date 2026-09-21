<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryCostRevaluationLine extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'old_unit_cost' => 'decimal:6', 'new_unit_cost' => 'decimal:6', 'variance_amount' => 'decimal:6'];

    public function run() { return $this->belongsTo(InventoryCostRevaluationRun::class, 'revaluation_run_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function costLayer() { return $this->belongsTo(InventoryCostLayer::class, 'cost_layer_id'); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
}
