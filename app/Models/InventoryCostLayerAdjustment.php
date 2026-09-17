<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryCostLayerAdjustment extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['old_unit_cost' => 'decimal:6', 'new_unit_cost' => 'decimal:6', 'adjustment_amount' => 'decimal:6', 'is_reversal' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(function (): void { throw new LogicException('Cost-layer adjustments are immutable.'); });
        static::deleting(function (): void { throw new LogicException('Cost-layer adjustments cannot be deleted.'); });
    }

    public function landedCost() { return $this->belongsTo(LandedCost::class); }
    public function costLayer() { return $this->belongsTo(InventoryCostLayer::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function adjuster() { return $this->belongsTo(User::class, 'adjusted_by'); }
    public function reversalOf() { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function reversal() { return $this->hasOne(self::class, 'reversal_of_id'); }
}
