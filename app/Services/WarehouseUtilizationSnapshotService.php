<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\WarehouseUtilizationSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WarehouseUtilizationSnapshotService
{
    public function capture(int $companyId, string|Carbon $asOfDate, ?int $createdBy = null): Collection
    {
        $date = $asOfDate instanceof Carbon
            ? $asOfDate->toDateString()
            : Carbon::createFromFormat('Y-m-d', $asOfDate)->toDateString();

        $locations = InventoryLocation::with([
                'warehouse',
                'movements.product:id,weight_kg,length_m,width_m,height_m',
            ])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('warehouse_id')
            ->orderBy('id')
            ->get();

        $children = $locations->groupBy(fn (InventoryLocation $location): string => (string) ($location->parent_id ?? 'root'));
        $capacity = app(InventoryLocationCapacityService::class);
        $snapshots = collect();

        foreach ($locations as $location) {
            $occupied = $capacity->occupied($location);
            $quantityCapacity = $location->capacity === null ? null : (float) $location->capacity;
            $weightCapacity = $location->capacity_weight_kg === null ? null : (float) $location->capacity_weight_kg;
            $volumeCapacity = $location->capacity_volume_m3 === null ? null : (float) $location->capacity_volume_m3;
            $descendants = $this->descendantCount((int) $location->id, $children);
            $attributes = [
                'company_id' => $companyId,
                'warehouse_id' => (int) $location->warehouse_id,
                'location_id' => (int) $location->id,
                'as_of_date' => $date,
                'occupied_quantity' => $occupied['quantity'],
                'capacity' => $quantityCapacity,
                'utilization_percent' => $quantityCapacity && $quantityCapacity > 0 ? min(100, ($occupied['quantity'] / $quantityCapacity) * 100) : null,
                'occupied_weight_kg' => $occupied['weight_kg'],
                'capacity_weight_kg' => $weightCapacity,
                'weight_utilization_percent' => $weightCapacity && $weightCapacity > 0 ? min(100, ($occupied['weight_kg'] / $weightCapacity) * 100) : null,
                'occupied_volume_m3' => $occupied['volume_m3'],
                'capacity_volume_m3' => $volumeCapacity,
                'volume_utilization_percent' => $volumeCapacity && $volumeCapacity > 0 ? min(100, ($occupied['volume_m3'] / $volumeCapacity) * 100) : null,
                'descendant_count' => $descendants,
            ];
            $snapshots->push(WarehouseUtilizationSnapshot::updateOrCreate(
                ['company_id' => $companyId, 'location_id' => (int) $location->id, 'as_of_date' => $date],
                $attributes
            ));
        }

        return $snapshots;
    }

    private function descendantCount(int $locationId, Collection $children): int
    {
        $count = 0;
        foreach ($children->get((string) $locationId, collect()) as $child) {
            $count++;
            $count += $this->descendantCount((int) $child->id, $children);
        }
        return $count;
    }
}
