<?php

namespace App\Services;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryCostingService
{
    public function receipt(int $productId, float $quantity, float $unitCost, ?int $locationId = null, ?int $batchId = null, ?InventoryMovement $movement = null, ?int $serialId = null): void
    {
        if ($quantity <= 0) return;
        $product = Product::whereKey($productId)->first(['id', 'costing_method', 'tracking_type', 'purchase_price', 'standard_cost']);
        $method = $product?->costing_method ?? 'fifo';
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
        $method = $product?->costing_method ?? 'fifo';
        if ($method === 'standard') {
            $standard = Product::whereKey($productId)->value('standard_cost');
            $cost = $fallbackCost ?? (float) ($standard ?? Product::whereKey($productId)->value('purchase_price') ?? 0);
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
