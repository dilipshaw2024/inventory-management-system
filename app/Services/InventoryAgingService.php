<?php

namespace App\Services;

use App\Models\InventoryCostLayer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InventoryAgingService
{
    /** @return list<string> */
    public function buckets(): array
    {
        return ['0-30', '31-60', '61-90', '91-180', '181-365', '366+'];
    }

    public function bucketForAge(int $days): string
    {
        return match (true) {
            $days <= 30 => '0-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            $days <= 180 => '91-180',
            $days <= 365 => '181-365',
            default => '366+',
        };
    }

    /**
     * Return remaining cost-layer quantities as of a cutoff date.
     * Consumption rows are replayed to the cutoff so historical reports do
     * not mistake today's remaining quantity for the historical balance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsForCompany(int $companyId, string $asOf, ?int $productId = null, ?int $categoryId = null, ?int $locationId = null): Collection
    {
        $cutoff = Carbon::parse($asOf)->endOfDay();
        $layers = InventoryCostLayer::withoutGlobalScopes()
            ->with(['product.category', 'location.warehouse', 'batch', 'consumptions.movement'])
            ->where('received_at', '<=', $cutoff)
            ->whereHas('product', function ($query) use ($companyId, $productId, $categoryId): void {
                $query->withoutGlobalScopes()
                    ->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->when($productId, fn ($productQuery, $id) => $productQuery->whereKey($id))
                    ->when($categoryId, fn ($productQuery, $id) => $productQuery->where('category_id', $id));
            })
            ->when($locationId, fn ($query, $id) => $query->where('location_id', $id))
            ->orderBy('received_at')->orderBy('id')->get();

        return $layers->map(function (InventoryCostLayer $layer) use ($cutoff): ?array {
            $consumed = (float) $layer->consumptions->filter(function ($consumption) use ($cutoff): bool {
                $date = $consumption->movement?->posted_at ?? $consumption->created_at;
                return $date !== null && Carbon::parse($date)->lte($cutoff);
            })->sum('quantity');
            $quantity = max(0, (float) $layer->original_quantity - $consumed);
            if ($quantity <= 0) return null;

            $receivedAt = Carbon::parse($layer->received_at);
            $ageDays = max(0, $receivedAt->startOfDay()->diffInDays($cutoff->copy()->startOfDay()));
            return [
                'cost_layer_id' => (int) $layer->id,
                'product_id' => (int) $layer->product_id,
                'product' => $layer->product?->name,
                'sku' => $layer->product?->sku,
                'category_id' => $layer->product?->category_id ? (int) $layer->product->category_id : null,
                'category' => $layer->product?->category?->name,
                'location_id' => $layer->location_id ? (int) $layer->location_id : null,
                'location' => $layer->location?->code,
                'warehouse_id' => $layer->location?->warehouse_id ? (int) $layer->location->warehouse_id : null,
                'warehouse' => $layer->location?->warehouse?->name,
                'batch_id' => $layer->batch_id ? (int) $layer->batch_id : null,
                'batch' => $layer->batch?->batch_no,
                'received_at' => $receivedAt->toDateString(),
                'age_days' => $ageDays,
                'age_bucket' => $this->bucketForAge($ageDays),
                'quantity' => round($quantity, 6),
                'unit_cost' => (float) $layer->unit_cost,
                'value' => round($quantity * (float) $layer->unit_cost, 6),
            ];
        })->filter()->values();
    }
}
