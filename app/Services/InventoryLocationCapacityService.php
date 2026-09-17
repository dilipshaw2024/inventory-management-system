<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Product;
use Illuminate\Support\Collection;

class InventoryLocationCapacityService
{
    private const INBOUND = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    /** @return array{quantity: float, weight_kg: float, volume_m3: float} */
    public function occupied(InventoryLocation $location): array
    {
        $rows = $location->relationLoaded('movements')
            ? $location->getRelation('movements')
            : $location->movements()->with('product:id,weight_kg,length_m,width_m,height_m')->get();
        return [
            'quantity' => $this->balance($rows, fn ($row): float => 1),
            'weight_kg' => $this->balance($rows, fn ($row): float => (float) ($row->product?->weight_kg ?? 0)),
            'volume_m3' => $this->balance($rows, fn ($row): float => (float) ($row->product?->length_m ?? 0) * (float) ($row->product?->width_m ?? 0) * (float) ($row->product?->height_m ?? 0)),
        ];
    }

    public function assertCanReceive(InventoryLocation $location, Product $product, float $quantity): void
    {
        app(InventoryLocationRestrictionService::class)->assertAllowed($location, $product);
        $occupied = $this->occupied($location);
        if ($location->capacity !== null && $occupied['quantity'] + $quantity > (float) $location->capacity + 0.000001) {
            throw new \RuntimeException('Location '.$location->code.' does not have enough available quantity capacity.');
        }
        $weight = (float) ($product->weight_kg ?? 0) * $quantity;
        if ($location->capacity_weight_kg !== null && $occupied['weight_kg'] + $weight > (float) $location->capacity_weight_kg + 0.000001) {
            throw new \RuntimeException('Location '.$location->code.' does not have enough available weight capacity.');
        }
        $volume = (float) ($product->length_m ?? 0) * (float) ($product->width_m ?? 0) * (float) ($product->height_m ?? 0) * $quantity;
        if ($location->capacity_volume_m3 !== null && $occupied['volume_m3'] + $volume > (float) $location->capacity_volume_m3 + 0.000001) {
            throw new \RuntimeException('Location '.$location->code.' does not have enough available volume capacity.');
        }
    }

    private function balance(Collection $rows, callable $factor): float
    {
        return max(0, (float) $rows->sum(function ($row) use ($factor): float {
            $sign = in_array($row->movement_type, self::INBOUND, true) ? 1 : (in_array($row->movement_type, self::OUTBOUND, true) ? -1 : 0);
            return $sign * (float) $row->quantity * $factor($row);
        }));
    }
}
