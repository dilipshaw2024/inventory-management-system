<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryMovement extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $appends = ['source_context'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Inventory movements are immutable; post a correcting movement instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('Inventory movements cannot be deleted; post a correcting movement instead.');
        });
    }

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'posted_at' => 'datetime',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function costCenter() { return $this->belongsTo(CostCenter::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class, 'batch_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'serial_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function allocations() { return $this->hasMany(InventoryMovementAllocation::class, 'movement_id'); }
    public function reference() { return $this->morphTo(); }
    public function getSourceContextAttribute(): ?array
    {
        return $this->relationLoaded('reference') ? app(\App\Services\InventoryMovementSourceContextService::class)->resolve($this->reference) : null;
    }

    public function finalizeUnitCost(float $unitCost): void
    {
        $this->forceFill(['unit_cost' => $unitCost])->saveQuietly();
    }
}
