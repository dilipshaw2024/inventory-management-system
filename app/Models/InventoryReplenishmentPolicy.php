<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryReplenishmentPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['reorder_point' => 'decimal:6', 'safety_stock' => 'decimal:6', 'service_level_z' => 'decimal:4', 'min_stock' => 'decimal:6', 'max_stock' => 'decimal:6', 'lead_time_days' => 'integer', 'safety_time_days' => 'integer', 'reorder_history_days' => 'integer', 'is_active' => 'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }

    protected static function booted(): void
    {
        static::saving(function (InventoryReplenishmentPolicy $policy): void {
            if (!$policy->product_id || !$policy->location_id) throw new \LogicException('A replenishment policy requires a product and location.');
            $product = (new Product())->newQueryWithoutScopes()->find($policy->product_id);
            $location = (new InventoryLocation())->newQueryWithoutScopes()->with('warehouse.branch')->find($policy->location_id);
            $productCompany = $product?->company_id;
            $locationCompany = $location?->warehouse?->branch?->company_id;
            if (!$product || !$location || ($productCompany && $locationCompany && (int) $productCompany !== (int) $locationCompany)) {
                throw new \LogicException('A replenishment policy product and location must belong to the same company.');
            }
        });
    }
}
