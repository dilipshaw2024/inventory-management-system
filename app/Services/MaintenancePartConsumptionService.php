<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePart;
use App\Models\Product;
use Illuminate\Support\Collection;

class MaintenancePartConsumptionService
{
    public function consume(
        MaintenanceOrder $order,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?int $locationId = null,
        ?int $batchId = null,
        array $serialNumbers = []
    ): Collection {
        app(ProductLifecycleService::class)->assertStockManaged($product);
        if ($quantity <= 0.000001) throw new \RuntimeException('Part quantity must be greater than zero.');
        $serialNumbers = array_values(array_unique(array_filter(array_map('trim', $serialNumbers))));

        // A maintenance reservation belongs to the same order and is consumed
        // before availability is checked, so it cannot reserve stock against
        // the order that is now issuing it.
        app(StockReservationService::class)->releaseForMaintenancePart($order, $product, $quantity, $locationId, $batchId);

        $batch = null;
        if ($batchId !== null) {
            $batch = InventoryBatch::whereKey($batchId)->where('product_id', $product->id)->lockForUpdate()->first();
            if (!$batch) throw new \RuntimeException('Selected batch does not belong to '.$product->name.'.');
            if ($batch->location_id && $locationId && (int) $batch->location_id !== $locationId) throw new \RuntimeException('Selected batch is not held at the selected location.');
        }

        if (app(InventoryAvailabilityService::class)->available($product, true, $locationId, $order->company_id) < $quantity) {
            throw new \RuntimeException('Insufficient available spare-part stock for '.$product->name.'.');
        }

        $unitCost ??= (float) ($product->purchase_price ?? 0);
        $parts = collect();
        $companyId = $order->company_id ?: auth()->user()?->company_id;
        if ($product->tracking_type === 'serial') {
            if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal consumed quantity for '.$product->name.'.');
            $serials = $serialNumbers
                ? app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $locationId, $batch?->id)
                : app(SerialLifecycleService::class)->issue($product, $quantity, $locationId, $batch?->id);
            foreach ($serials->groupBy(fn ($serial): int => (int) ($serial->batch_id ?: $batch?->id ?: 0)) as $serialBatchId => $group) {
                $cost = 0.0;
                foreach ($group as $serial) {
                    $movement = app(InventoryLedgerService::class)->post($product->id, 'issue', 1, $unitCost, $locationId, $order, 'Maintenance spare-part consumption', null, $serial->batch_id ?: ($serialBatchId ?: null), $serial->id);
                    $cost += (float) $movement->unit_cost;
                }
                $parts->push(MaintenancePart::create([
                    'company_id' => $companyId, 'maintenance_order_id' => $order->id, 'product_id' => $product->id,
                    'location_id' => $locationId, 'batch_id' => $serialBatchId ?: null, 'serial_numbers' => $group->pluck('serial_no')->implode(','),
                    'quantity' => $group->count(), 'unit_cost' => $group->count() ? $cost / $group->count() : $unitCost,
                ]));
            }
        } else {
            $product->quantity = (float) $product->quantity - $quantity;
            $product->save();
            $movement = app(InventoryLedgerService::class)->post($product->id, 'issue', $quantity, $unitCost, $locationId, $order, 'Maintenance spare-part consumption', null, $batch?->id);
            $parts->push(MaintenancePart::create([
                'company_id' => $companyId, 'maintenance_order_id' => $order->id, 'product_id' => $product->id,
                'location_id' => $locationId, 'batch_id' => $batch?->id, 'quantity' => $quantity, 'unit_cost' => $movement->unit_cost,
            ]));
        }
        if ($product->tracking_type === 'serial') {
            $product->quantity = (float) $product->quantity - $quantity;
            $product->save();
        }
        if ($order->status === 'planned') $order->update(['status' => 'in_progress']);
        return $parts;
    }
}
