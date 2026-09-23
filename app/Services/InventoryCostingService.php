<?php

namespace App\Services;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryCostingService
{
    /**
     * Preview open-layer cost variances without changing layers, movements, or journals.
     *
     * Weighted/moving-average products converge their open layers to the
     * quantity-weighted average; standard-cost products converge to standard_cost.
     */
    public function revaluationPreviewForCompany(int $companyId, ?string $asOf = null, ?int $productId = null, ?int $locationId = null): Collection
    {
        $products = Product::withoutGlobalScope('company')
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where('status', 1)->whereIn('costing_method', ['weighted_average', 'moving_average', 'standard'])
            ->where(fn ($query) => $query->whereIn('costing_method', ['weighted_average', 'moving_average'])->orWhereNotNull('standard_cost'))
            ->when($productId, fn ($query, $id) => $query->whereKey($id))->get()->keyBy('id');
        if ($products->isEmpty()) return collect();

        $cutoff = $asOf ? CarbonImmutable::parse($asOf)->endOfDay() : null;
        $layers = InventoryCostLayer::withoutGlobalScopes()
            ->with(['consumptions.movement', 'location'])
            ->whereIn('product_id', $products->keys())
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($cutoff, fn ($query) => $query->where('received_at', '<=', $cutoff))
            ->when(!$cutoff, fn ($query) => $query->where('remaining_quantity', '>', 0))
            ->orderBy('product_id')->orderBy('received_at')->orderBy('id')->get();

        return $layers->groupBy('product_id')->map(function (Collection $productLayers, $id) use ($products, $cutoff): ?array {
            $product = $products->get($id);
            $policy = app(ProductCostingPolicyService::class)->resolve($product, $cutoff ?: now());
            $rows = $productLayers->map(function (InventoryCostLayer $layer) use ($cutoff): array {
                $consumed = $cutoff
                    ? (float) $layer->consumptions->filter(function ($consumption) use ($cutoff): bool {
                        $date = $consumption->movement?->posted_at ?? $consumption->created_at;
                        return $date !== null && $date <= $cutoff;
                    })->sum('quantity')
                    : (float) $layer->original_quantity - (float) $layer->remaining_quantity;
                $quantity = $cutoff
                    ? max(0, (float) $layer->original_quantity - $consumed)
                    : (float) $layer->remaining_quantity;
                return ['layer_id' => (int) $layer->id, 'location_id' => $layer->location_id, 'location_code' => $layer->location?->code, 'quantity' => $quantity, 'current_unit_cost' => (float) $layer->unit_cost];
            })->filter(fn (array $row): bool => $row['quantity'] > 0.000001)->values();
            if ($rows->isEmpty()) return null;

            $totalQuantity = (float) $rows->sum('quantity');
            $target = $policy['costing_method'] === 'standard'
                ? (float) ($policy['standard_cost'] ?? 0)
                : (float) $rows->sum(fn (array $row): float => $row['quantity'] * $row['current_unit_cost']) / max($totalQuantity, 0.000001);
            $lines = $rows->map(function (array $row) use ($target): array {
                $variance = $row['quantity'] * ($target - $row['current_unit_cost']);
                return $row + ['target_unit_cost' => round($target, 6), 'variance_amount' => round($variance, 6)];
            })->filter(fn (array $row): bool => abs($row['variance_amount']) > 0.000001)->values();
            return ['product_id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku, 'costing_method' => $policy['costing_method'], 'policy_id' => $policy['policy_id'], 'as_of' => $cutoff?->toDateString(), 'quantity' => round($totalQuantity, 6), 'current_value' => round((float) $rows->sum(fn (array $row): float => $row['quantity'] * $row['current_unit_cost']), 6), 'target_unit_cost' => round($target, 6), 'target_value' => round($totalQuantity * $target, 6), 'variance_amount' => round((float) $lines->sum('variance_amount'), 6), 'revaluation_required' => $lines->isNotEmpty(), 'lines' => $lines];
        })->filter()->values();
    }

    public function receipt(int $productId, float $quantity, float $unitCost, ?int $locationId = null, ?int $batchId = null, ?InventoryMovement $movement = null, ?int $serialId = null): void
    {
        if ($quantity <= 0) return;
        $product = Product::whereKey($productId)->first(['id', 'costing_method', 'tracking_type', 'purchase_price', 'standard_cost']);
        $policy = app(ProductCostingPolicyService::class)->resolve($product, $movement?->posted_at);
        $method = $policy['costing_method'];
        if (in_array($method, ['weighted_average', 'moving_average'], true)) {
            $layers = InventoryCostLayer::where('product_id', $productId)->where('remaining_quantity', '>', 0)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->lockForUpdate()->get();
            $oldQuantity = (float) $layers->sum('remaining_quantity'); $oldValue = (float) $layers->sum(fn ($layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost);
            $average = ($oldValue + ($quantity * $unitCost)) / max($oldQuantity + $quantity, 0.000001);
            foreach ($layers as $layer) { $layer->update(['unit_cost' => $average]); }
            $unitCost = $average;
        }
        InventoryCostLayer::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'department_id' => $movement?->department_id,
            'cost_center_id' => $movement?->cost_center_id,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'source_type' => $movement?->reference_type,
            'source_id' => $movement?->reference_id,
            'received_at' => $movement?->posted_at ?? now(),
        ]);
        if ($movement) {
            InventoryMovementAllocation::create([
                'movement_id' => $movement->id,
                'product_id' => $productId,
                'batch_id' => $batchId,
                'serial_id' => $serialId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);
        }
    }

    public function consume(int $productId, float $quantity, ?int $locationId = null, ?InventoryMovement $movement = null, ?float $fallbackCost = null, ?int $batchId = null, ?int $serialId = null): float
    {
        if ($quantity <= 0) return 0;
        $product = Product::whereKey($productId)->first(['id', 'costing_method', 'tracking_type', 'purchase_price', 'standard_cost']);
        $policy = app(ProductCostingPolicyService::class)->resolve($product, $movement?->posted_at);
        $method = $policy['costing_method'];
        if ($method === 'standard') {
            $standard = $policy['standard_cost'];
            $cost = $fallbackCost ?? (float) ($standard ?? $product?->purchase_price ?? 0);
            if ($movement) {
                InventoryMovementAllocation::create([
                    'movement_id' => $movement->id,
                    'product_id' => $productId,
                    'batch_id' => $batchId,
                    'serial_id' => $serialId,
                    'quantity' => $quantity,
                    'unit_cost' => $cost,
                ]);
            }
            return $quantity * $cost;
        }
        $remaining = $quantity;
        $totalCost = 0;
        $hasBatchDate = false;
        $layers = InventoryCostLayer::with('batch')->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        if (in_array($product?->tracking_type, ['batch', 'lot'], true)) {
            $layers = $layers->sortBy(fn ($layer) => ($layer->batch?->expiry_date ?? $layer->batch?->best_before_date)?->timestamp ?? PHP_INT_MAX)->values();
            $hasBatchDate = $layers->contains(fn ($layer) => $layer->batch?->expiry_date !== null || $layer->batch?->best_before_date !== null);
            if ($hasBatchDate) {
                $allowExpired = app(ErpSettingService::class)->get('allow_expired_batch_issue', false);
                $allowPastBestBefore = app(ErpSettingService::class)->get('allow_past_best_before_issue', false);
                $layers = $layers->filter(fn ($layer): bool => ($allowExpired || !$layer->batch?->expiry_date || !$layer->batch->expiry_date->lt(\Carbon\Carbon::today())) && ($allowPastBestBefore || !$layer->batch?->best_before_date || !$layer->batch->best_before_date->lt(\Carbon\Carbon::today())))->values();
            }
        }

        foreach ($layers as $layer) {
            if ($remaining <= 0) break;
            $consumed = min($remaining, (float) $layer->remaining_quantity);
            $layer->remaining_quantity = (float) $layer->remaining_quantity - $consumed;
            $layer->save();
            $cost = $consumed * (float) $layer->unit_cost;
            $totalCost += $cost;
            InventoryCostConsumption::create([
                'cost_layer_id' => $layer->id,
                'product_id' => $productId,
                'movement_id' => $movement?->id,
                'quantity' => $consumed,
                'unit_cost' => $layer->unit_cost,
                'total_cost' => $cost,
                'costing_method' => $method,
            ]);
            if ($movement) {
                InventoryMovementAllocation::create([
                    'movement_id' => $movement->id,
                    'product_id' => $productId,
                    'batch_id' => $layer->batch_id,
                    'serial_id' => $serialId,
                    'quantity' => $consumed,
                    'unit_cost' => $layer->unit_cost,
                ]);
            }
            $remaining -= $consumed;
        }

        if ($remaining > 0) {
            if ($batchId !== null) throw new \RuntimeException('Insufficient stock in the selected batch.');
            if (in_array($product?->tracking_type, ['batch', 'lot'], true) && $hasBatchDate) {
                throw new \RuntimeException('Insufficient non-expired batch stock for '.($product?->name ?? 'product').'.');
            }
            $cost = $fallbackCost ?? (float) ($product?->purchase_price ?? 0);
            $totalCost += $remaining * $cost;
        }
        return $totalCost;
    }
}
