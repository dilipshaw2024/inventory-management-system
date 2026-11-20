<?php

namespace App\Services;

use App\Models\InventoryMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InventoryMovementMetricsService
{
    /** @return Collection<int, float> */
    public function issueQuantitiesSince(Collection $productIds, Carbon $cutoff, int $companyId, ?int $locationId = null): Collection
    {
        return InventoryMovement::query()
            ->where('movement_type', 'issue')->whereIn('product_id', $productIds)
            ->where('posted_at', '>=', $cutoff)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->selectRaw('product_id, SUM(quantity) as issued_quantity')->groupBy('product_id')
            ->pluck('issued_quantity', 'product_id')->map(fn ($quantity): float => (float) $quantity);
    }

    public function isSlowMoving(float $issuedQuantity, int $days, float $velocityThreshold): bool
    {
        return $velocityThreshold <= 0
            ? $issuedQuantity <= 0.000001
            : ($issuedQuantity / max(1, $days)) <= $velocityThreshold;
    }
}
