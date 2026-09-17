<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LandedCost extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'reversed_at' => 'datetime'];
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class); }
    public function allocations() { return $this->hasMany(LandedCostAllocation::class); }
    public function layerAdjustments() { return $this->hasMany(InventoryCostLayerAdjustment::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function reverser() { return $this->belongsTo(User::class, 'reversed_by'); }
}
