<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\InventoryLocation;

class WarehousePickRouteService
{
    /**
     * Build a deterministic root-to-bin path from the existing location
     * hierarchy. Locations without parents remain backward-compatible and
     * use their own code as the route key.
     */
    public function locationKey(?InventoryLocation $location): string
    {
        if (!$location) return 'ZZZ';
        $segments = [];
        $current = $location;
        $guard = 0;
        while ($current && $guard++ < 32) {
            $segments[] = (string) ($current->code ?: $current->name ?: $current->id);
            $current = $current->relationLoaded('parent') ? $current->parent : null;
        }
        return implode(' / ', array_reverse($segments));
    }

    /** @return array{0:int,1:string,2:string} */
    public function deliveryKey(Delivery $delivery): array
    {
        return [
            (int) ($delivery->location?->warehouse_id ?? 0),
            $this->locationKey($delivery->location),
            (string) ($delivery->date?->toDateString() ?? '').'|'.(int) $delivery->id,
        ];
    }
}
