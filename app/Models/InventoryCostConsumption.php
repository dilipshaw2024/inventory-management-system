<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryCostConsumption extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6'];
    public function layer() { return $this->belongsTo(InventoryCostLayer::class, 'cost_layer_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function movement() { return $this->belongsTo(InventoryMovement::class, 'movement_id'); }
}
