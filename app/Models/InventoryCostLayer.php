<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryCostLayer extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['original_quantity' => 'decimal:6', 'remaining_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'received_at' => 'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
    public function batch() { return $this->belongsTo(InventoryBatch::class, 'batch_id'); }
    public function consumptions() { return $this->hasMany(InventoryCostConsumption::class, 'cost_layer_id'); }
    public function adjustments() { return $this->hasMany(InventoryCostLayerAdjustment::class, 'cost_layer_id'); }
}
