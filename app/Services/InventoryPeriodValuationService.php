<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventoryPeriodValuationService
{
    private const INBOUND = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    /**
     * Reconcile an accounting period's opening and closing inventory value
     * against the immutable movements posted inside the period.
     *
     * Cost layers are preferred for opening/closing values. Legacy movement
     * cost is used only for products without cost-layer history.
     */
    public function drilldown(FiscalPeriod $period, array $filters = []): array
    {
        $companyId = (int) $period->company_id;
        $products = Product::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->orderBy('id')->get(['id', 'name', 'sku']);
        $productIds = $products->pluck('id');
        if ($productIds->isEmpty()) {
            return ['data' => collect(), 'summary' => $this->emptySummary(), 'meta' => ['closing_source' => 'none']];
        }

        $opening = $this->valuesAt($companyId, $productIds, CarbonImmutable::parse($period->starts_on)->subDay()->endOfDay(), $filters);
        $closing = $this->valuesAt($companyId, $productIds, CarbonImmutable::parse($period->ends_on)->endOfDay(), $filters);
        $movementGroups = $this->periodMovements($companyId, $productIds, $period, $filters);
        $keys = collect(array_keys($opening))->merge(array_keys($closing))->merge(array_keys($movementGroups))->unique()->values();

        $rows = $keys->map(function (string $key) use ($products, $opening, $closing, $movementGroups): array {
            [$productId, $locationId] = explode('|', $key, 2);
            $product = $products->get($productId);
            $openingValue = (float) ($opening[$key]['value'] ?? 0);
            $closingValue = (float) ($closing[$key]['value'] ?? 0);
            $movement = $movementGroups[$key] ?? ['inbound_value' => 0, 'outbound_value' => 0, 'movement_count' => 0];
            $expectedClosing = $openingValue + (float) $movement['inbound_value'] - (float) $movement['outbound_value'];

            return [
                'product_id' => (int) $productId,
                'product' => $product?->name,
                'sku' => $product?->sku,
                'location_id' => $locationId === 'null' ? null : (int) $locationId,
                'opening_value' => round($openingValue, 6),
                'inbound_value' => round((float) $movement['inbound_value'], 6),
                'outbound_value' => round((float) $movement['outbound_value'], 6),
                'expected_closing_value' => round($expectedClosing, 6),
                'closing_value' => round($closingValue, 6),
                'unexplained_variance' => round($expectedClosing - $closingValue, 6),
                'movement_count' => (int) $movement['movement_count'],
                'opening_source' => $opening[$key]['source'] ?? 'none',
                'closing_source' => $closing[$key]['source'] ?? 'none',
            ];
        })->filter(fn (array $row): bool => $row['movement_count'] > 0 || abs($row['opening_value']) > 0.000001 || abs($row['closing_value']) > 0.000001)->values();

        return [
            'data' => $rows,
            'summary' => [
                'row_count' => $rows->count(),
                'opening_value' => round((float) $rows->sum('opening_value'), 6),
                'inbound_value' => round((float) $rows->sum('inbound_value'), 6),
                'outbound_value' => round((float) $rows->sum('outbound_value'), 6),
                'expected_closing_value' => round((float) $rows->sum('expected_closing_value'), 6),
                'closing_value' => round((float) $rows->sum('closing_value'), 6),
                'unexplained_variance' => round((float) $rows->sum('unexplained_variance'), 6),
                'movement_count' => (int) $rows->sum('movement_count'),
            ],
            'meta' => [
                'closing_source' => $rows->contains(fn (array $row): bool => $row['closing_source'] === 'movement_cost') ? 'mixed_cost_layers_and_movement_cost' : 'cost_layers',
                'read_only' => true,
            ],
        ];
    }

    private function valuesAt(int $companyId, Collection $productIds, CarbonImmutable $cutoff, array $filters): array
    {
        $layers = InventoryCostLayer::withoutGlobalScopes()
            ->with('consumptions.movement')
            ->whereIn('product_id', $productIds)
            ->whereDate('received_at', '<=', $cutoff->toDateString())
            ->when(array_key_exists('location_id', $filters), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when($filters['batch_id'] ?? null, fn ($query, $id) => $query->where('batch_id', $id))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['cost_center_id'] ?? null, fn ($query, $id) => $query->where('cost_center_id', $id))
            ->get();
        $values = [];
        foreach ($layers as $layer) {
            $consumed = $layer->consumptions->filter(function ($consumption) use ($cutoff): bool {
                $date = $consumption->movement?->posted_at ?? $consumption->created_at;
                return $date !== null && CarbonImmutable::parse($date)->lte($cutoff);
            })->sum('quantity');
            $remaining = max(0, (float) $layer->original_quantity - (float) $consumed);
            if ($remaining <= 0.000001) continue;
            $key = $this->key((int) $layer->product_id, $layer->location_id);
            $values[$key]['value'] = ($values[$key]['value'] ?? 0) + ($remaining * (float) $layer->unit_cost);
            $values[$key]['source'] = 'cost_layers';
        }

        $fallback = $this->movementValuesAt($companyId, $productIds, $cutoff, $filters);
        foreach ($fallback as $key => $value) {
            if (!isset($values[$key])) $values[$key] = ['value' => $value, 'source' => 'movement_cost'];
        }
        return $values;
    }

    private function movementValuesAt(int $companyId, Collection $productIds, CarbonImmutable $cutoff, array $filters): array
    {
        $movements = $this->movementQuery($companyId, $productIds, $filters)
            ->where('posted_at', '<=', $cutoff)
            ->get(['product_id', 'location_id', 'movement_type', 'quantity', 'unit_cost']);
        $values = [];
        foreach ($movements as $movement) {
            $key = $this->key((int) $movement->product_id, $movement->location_id);
            $quantity = (float) $movement->quantity;
            $amount = $quantity * (float) ($movement->unit_cost ?? 0);
            if (in_array($movement->movement_type, self::INBOUND, true)) $values[$key] = ($values[$key] ?? 0) + $amount;
            if (in_array($movement->movement_type, self::OUTBOUND, true)) $values[$key] = ($values[$key] ?? 0) - $amount;
        }
        return $values;
    }

    private function periodMovements(int $companyId, Collection $productIds, FiscalPeriod $period, array $filters): array
    {
        $movements = $this->movementQuery($companyId, $productIds, $filters)
            ->whereBetween('posted_at', [CarbonImmutable::parse($period->starts_on)->startOfDay(), CarbonImmutable::parse($period->ends_on)->endOfDay()])
            ->with('allocations')->get();
        $groups = [];
        foreach ($movements as $movement) {
            $key = $this->key((int) $movement->product_id, $movement->location_id);
            $value = $movement->allocations->isNotEmpty()
                ? (float) $movement->allocations->sum(fn ($allocation): float => (float) $allocation->quantity * (float) ($allocation->unit_cost ?? 0))
                : (float) $movement->quantity * (float) ($movement->unit_cost ?? 0);
            $groups[$key] ??= ['inbound_value' => 0, 'outbound_value' => 0, 'movement_count' => 0];
            if (in_array($movement->movement_type, self::INBOUND, true)) $groups[$key]['inbound_value'] += $value;
            if (in_array($movement->movement_type, self::OUTBOUND, true)) $groups[$key]['outbound_value'] += $value;
            $groups[$key]['movement_count']++;
        }
        return $groups;
    }

    private function movementQuery(int $companyId, Collection $productIds, array $filters)
    {
        return InventoryMovement::withoutGlobalScopes()
            ->whereIn('product_id', $productIds)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when(array_key_exists('location_id', $filters), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when($filters['batch_id'] ?? null, fn ($query, $id) => $query->where('batch_id', $id))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['cost_center_id'] ?? null, fn ($query, $id) => $query->where('cost_center_id', $id));
    }

    private function key(int $productId, ?int $locationId): string
    {
        return $productId.'|'.($locationId ?? 'null');
    }

    private function emptySummary(): array
    {
        return ['row_count' => 0, 'opening_value' => 0, 'inbound_value' => 0, 'outbound_value' => 0, 'expected_closing_value' => 0, 'closing_value' => 0, 'unexplained_variance' => 0, 'movement_count' => 0];
    }
}
