<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovementAllocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
    ];

    public function movement() { return $this->belongsTo(InventoryMovement::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function serial() { return $this->belongsTo(InventorySerial::class); }

    protected static function booted(): void
    {
        static::updating(function (): void { throw new \LogicException('Movement allocations are immutable.'); });
        static::deleting(function (): void { throw new \LogicException('Movement allocations cannot be deleted.'); });
    }
}
