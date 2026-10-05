<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockReservation;
use App\Models\ProductionOrder;
use App\Models\MaintenanceOrder;
use App\Models\InventoryLocation;
use App\Models\InventoryCostLayer;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use App\Services\ErpSettingService;

class StockReservationService
{
    public function batchAllocations(int $salesOrderLineId, float $quantity, ?int $explicitBatchId = null, ?int $locationId = null, ?int $productId = null): Collection
    {
        if ($quantity <= 0.000001) return collect();
        if ($explicitBatchId !== null) return collect([['batch_id' => $explicitBatchId, 'quantity' => $quantity]]);

        $remaining = $quantity;
        $allocations = collect();
        $reservations = StockReservation::where('sales_order_line_id', $salesOrderLineId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->when($productId !== null, fn ($query) => $query->where('product_id', $productId))->when($locationId !== null, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('location_id')->orWhere('location_id', $locationId)))->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($remaining <= 0.000001) break;
            $allocated = min($remaining, $reservation->open_quantity);
            if ($allocated <= 0.000001) continue;
            $allocations->push(['batch_id' => $reservation->batch_id, 'quantity' => $allocated]);
            $remaining -= $allocated;
        }
        if ($remaining > 0.000001) $allocations->push(['batch_id' => null, 'quantity' => $remaining]);
        return $allocations;
    }

    public function reserveSalesOrder(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            foreach ($order->lines as $line) {
                $required = (float) $line->ordered_qty - (float) $line->delivered_qty;
                if ($required <= 0) continue;
                $product = Product::lockForUpdate()->findOrFail($line->product_id);
                if (($product->product_type ?: 'stock') === 'bundle') {
                    $requirements = app(BundleFulfillmentService::class)->requirements($product, $required);
                    foreach ($requirements as $componentId => $componentQuantity) {
                        $component = Product::lockForUpdate()->findOrFail($componentId);
                        $available = app(InventoryAvailabilityService::class)->available($component, true, $order->location_id, $order->company_id);
                        if (!$order->allow_backorders && $available < $componentQuantity) throw new \RuntimeException('Insufficient available stock for bundle component '.$component->name.'.');
                        $reserve = $order->allow_backorders ? min($componentQuantity, max(0, $available)) : $componentQuantity;
                        if ($reserve > 0.000001) $this->createBatchReservations($component, $reserve, $order->location_id, ['sales_order_line_id' => $line->id]);
                    }
                    continue;
                }
                $available = app(InventoryAvailabilityService::class)->available($product, true, $order->location_id, $order->company_id);
                if (!$order->allow_backorders && $available < $required) throw new \RuntimeException('Insufficient available stock for '.$product->name.'.');
                $reserve = $order->allow_backorders ? min($required, max(0, $available)) : $required;
                if ($reserve > 0.000001) {
                    $this->createBatchReservations($product, $reserve, $order->location_id, ['sales_order_line_id' => $line->id], $line->batch_id ? (int) $line->batch_id : null, (bool) $order->allow_backorders);
                }
            }
        });
    }

    private function createBatchReservations(Product $product, float $quantity, ?int $locationId, array $attributes = [], ?int $preferredBatchId = null, bool $allowBackorders = false): void
    {
        $base = $attributes + ['product_id' => $product->id, 'location_id' => $locationId, 'created_by' => auth()->id(), 'expires_at' => $this->reservationExpiry($product->company_id)];
        if ($preferredBatchId !== null) {
            if (!in_array($product->tracking_type, ['batch', 'lot'], true)) throw new \RuntimeException('A preferred batch can only be selected for batch- or lot-tracked products.');
            $batch = InventoryBatch::whereKey($preferredBatchId)->where('product_id', $product->id)->first();
            if (!$batch) throw new \RuntimeException('The selected batch does not belong to '.$product->name.'.');
            $available = (float) InventoryCostLayer::where('product_id', $product->id)->where('batch_id', $preferredBatchId)->where('remaining_quantity', '>', 0)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->sum('remaining_quantity');
            $reserved = (float) StockReservation::where('product_id', $product->id)->where('batch_id', $preferredBatchId)->where('location_id', $locationId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum(DB::raw('quantity - released_quantity'));
            $open = max(0, $available - $reserved);
            if (!$allowBackorders && $open + 0.000001 < $quantity) throw new \RuntimeException('Insufficient available stock in the selected batch for '.$product->name.'.');
            $quantity = $allowBackorders ? min($quantity, $open) : $quantity;
            if ($quantity > 0.000001) StockReservation::create($base + ['batch_id' => $preferredBatchId, 'quantity' => $quantity]);
            return;
        }
        if (!in_array($product->tracking_type, ['batch', 'lot'], true)) {
            StockReservation::create($base + ['quantity' => $quantity]);
            return;
        }

        $layers = InventoryCostLayer::with('batch')->where('product_id', $product->id)->whereNotNull('batch_id')->where('remaining_quantity', '>', 0)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->lockForUpdate()->get();
        $batches = $layers->groupBy('batch_id')->map(fn ($batchLayers) => (float) $batchLayers->sum('remaining_quantity'))->sortBy(function ($available, $batchId) use ($layers): int {
            $batch = $layers->firstWhere('batch_id', $batchId)?->batch;
            return ($batch?->expiry_date ?? $batch?->best_before_date)?->timestamp ?? PHP_INT_MAX;
        });
        $reserved = StockReservation::where('product_id', $product->id)->where('location_id', $locationId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->whereIn('batch_id', $batches->keys())->get()->groupBy('batch_id')->map(fn ($rows) => (float) $rows->sum(fn ($row) => $row->open_quantity));
        $remaining = $quantity;
        foreach ($batches as $batchId => $available) {
            if ($remaining <= 0.000001) break;
            $open = max(0, $available - (float) ($reserved[$batchId] ?? 0));
            $allocated = min($remaining, $open);
            if ($allocated <= 0.000001) continue;
            StockReservation::create($base + ['batch_id' => $batchId, 'quantity' => $allocated]);
            $remaining -= $allocated;
        }
        if ($remaining > 0.000001) StockReservation::create($base + ['quantity' => $remaining]);
    }

    public function restoreReleasedSerial(StockReservation $reservation): void
    {
        if (!$reservation->serial_id) return;
        InventorySerial::whereKey($reservation->serial_id)->where('status', 'reserved')->update(['status' => 'available']);
    }

    public function releaseForSalesOrderLine(int $lineId, float $quantity, ?int $productId = null): void
    {
        $remaining = $quantity;
        $reservations = StockReservation::where('sales_order_line_id', $lineId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->when($productId !== null, fn ($query) => $query->where('product_id', $productId))->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($remaining <= 0) break;
            $release = min($remaining, $reservation->open_quantity);
            $reservation->released_quantity = (float) $reservation->released_quantity + $release;
            if ($reservation->open_quantity <= 0.000001) $reservation->status = 'released';
            $reservation->save();
            if ($reservation->status === 'released') $this->restoreReleasedSerial($reservation);
            $remaining -= $release;
        }
    }

    public function releaseSalesOrder(SalesOrder $order): int
    {
        $reservations = StockReservation::whereIn('sales_order_line_id', $order->lines->pluck('id'))->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            $reservation->update(['released_quantity' => $reservation->quantity, 'status' => 'released']);
            $this->restoreReleasedSerial($reservation);
        }
        return $reservations->count();
    }

    public function reserveProductionOrder(ProductionOrder $order, array $requirements): void
    {
        foreach ($requirements as $productId => $quantity) {
            $product = Product::lockForUpdate()->findOrFail($productId);
            if (app(InventoryAvailabilityService::class)->available($product, true, $order->location_id, $order->company_id) < (float) $quantity) throw new \RuntimeException('Insufficient available stock to reserve '.$product->name.'.');
            $this->createBatchReservations($product, (float) $quantity, $order->location_id, ['source_type' => $order->getMorphClass(), 'source_id' => $order->id]);
        }
    }

    /** Reserve a spare-part quantity for a maintenance order before issue. */
    public function reserveMaintenancePart(MaintenanceOrder $order, Product $product, float $quantity, ?int $locationId = null, ?int $preferredBatchId = null): Collection
    {
        if ($quantity <= 0.000001) throw new \RuntimeException('Reserved part quantity must be greater than zero.');
        app(ProductLifecycleService::class)->assertStockManaged($product);
        if (in_array($order->status, ['completed', 'cancelled'], true)) throw new \RuntimeException('Closed maintenance orders cannot reserve spare parts.');
        if (app(InventoryAvailabilityService::class)->available($product, true, $locationId, $order->company_id) < $quantity) {
            throw new \RuntimeException('Insufficient available stock to reserve '.$product->name.'.');
        }
        $this->createBatchReservations(
            $product,
            $quantity,
            $locationId,
            ['source_type' => $order->getMorphClass(), 'source_id' => $order->id],
            $preferredBatchId
        );
        return StockReservation::with(['product', 'batch', 'location'])
            ->where('source_type', $order->getMorphClass())->where('source_id', $order->id)
            ->where('product_id', $product->id)->where('status', 'active')->orderByDesc('id')->get();
    }

    /** Release the order's matching reservation as the part is physically issued. */
    public function releaseForMaintenancePart(MaintenanceOrder $order, Product $product, float $quantity, ?int $locationId = null, ?int $batchId = null): float
    {
        $remaining = $quantity;
        $reservations = StockReservation::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)->where('product_id', $product->id)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($remaining <= 0.000001) break;
            $release = min($remaining, $reservation->open_quantity);
            if ($release <= 0.000001) continue;
            $reservation->released_quantity = (float) $reservation->released_quantity + $release;
            if ($reservation->open_quantity <= 0.000001) $reservation->status = 'released';
            $reservation->save();
            if ($reservation->status === 'released') $this->restoreReleasedSerial($reservation);
            $remaining -= $release;
        }
        return $quantity - $remaining;
    }

    public function releaseForSource(ProductionOrder $order): void
    {
        $reservations = StockReservation::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->lockForUpdate()->get();
        foreach ($reservations as $reservation) { $reservation->update(['released_quantity' => $reservation->quantity, 'status' => 'released']); $this->restoreReleasedSerial($reservation); }
    }

    public function releaseForSourceQuantity(ProductionOrder $order, float $quantity): void
    {
        $remaining = $quantity;
        $reservations = StockReservation::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($remaining <= 0.000001) break;
            $release = min($remaining, (float) $reservation->open_quantity);
            $reservation->released_quantity = (float) $reservation->released_quantity + $release;
            if ($reservation->open_quantity <= 0.000001) $reservation->status = 'released';
            $reservation->save();
            if ($reservation->status === 'released') $this->restoreReleasedSerial($reservation);
            $remaining -= $release;
        }
        if ($remaining > 0.000001) throw new \RuntimeException('Production reservations do not cover the requested completion quantity.');
    }

    public function releaseForSourceRequirements(ProductionOrder $order, array $requirements): void
    {
        foreach ($requirements as $productId => $quantity) {
            $remaining = (float) $quantity;
            $reservations = StockReservation::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('product_id', $productId)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                if ($remaining <= 0.000001) break;
                $release = min($remaining, (float) $reservation->open_quantity);
                $reservation->released_quantity = (float) $reservation->released_quantity + $release;
                if ($reservation->open_quantity <= 0.000001) $reservation->status = 'released';
                $reservation->save();
                if ($reservation->status === 'released') $this->restoreReleasedSerial($reservation);
                $remaining -= $release;
            }
            if ($remaining > 0.000001) throw new \RuntimeException('Production reservations do not cover the requested component quantity.');
        }
    }

    public function restoreForSourceRequirements(ProductionOrder $order, array $requirements): void
    {
        foreach ($requirements as $productId => $quantity) {
            $quantity = (float) $quantity;
            if ($quantity <= 0.000001) continue;
            $product = Product::where(function ($query) use ($order): void {
                $query->where('company_id', $order->company_id)->orWhereNull('company_id');
            })->lockForUpdate()->findOrFail($productId);
            if (app(InventoryAvailabilityService::class)->available($product, true, $order->location_id, $order->company_id) < $quantity - 0.000001) {
                throw new \RuntimeException('Insufficient available stock to restore the production reservation for '.$product->name.'.');
            }
            $this->createBatchReservations($product, $quantity, $order->location_id, ['source_type' => $order->getMorphClass(), 'source_id' => $order->id]);
        }
    }

    /** @return list<StockReservation> */
    public function assignSerials(StockReservation $reservation, array $serialIds): array
    {
        return DB::transaction(function () use ($reservation, $serialIds): array {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            $serialIds = array_values(array_unique(array_map('intval', array_filter($serialIds, fn ($id): bool => (int) $id > 0))));
            if ($serialIds === []) throw new \RuntimeException('At least one serial is required.');
            if ($reservation->status !== 'active' || $reservation->open_quantity <= 0.000001) throw new \RuntimeException('Only open reservations can receive serial assignments.');
            if (abs($reservation->open_quantity - round($reservation->open_quantity)) > 0.000001 || count($serialIds) > (int) round($reservation->open_quantity)) throw new \RuntimeException('Serial assignments must not exceed the open whole-unit reservation quantity.');
            $product = Product::lockForUpdate()->findOrFail($reservation->product_id);
            if ($product->tracking_type !== 'serial') throw new \RuntimeException('Serial assignment is only available for serial-tracked products.');
            $serials = InventorySerial::whereIn('id', $serialIds)->where('product_id', $product->id)->whereIn('status', ['available', 'returned'])
                ->when($reservation->batch_id !== null, fn ($query) => $query->where('batch_id', $reservation->batch_id))
                ->when($reservation->location_id !== null, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('location_id')->orWhere('location_id', $reservation->location_id)))
                ->lockForUpdate()->get()->keyBy('id');
            if ($serials->count() !== count($serialIds)) throw new \RuntimeException('One or more selected serials are unavailable for '.$product->name.'.');
            $serials->each(fn (InventorySerial $serial): bool => (bool) $serial->update(['status' => 'reserved']));
            $alreadyReserved = StockReservation::whereIn('serial_id', $serialIds)->where('status', 'active')->where('id', '!=', $reservation->id)->exists();
            if ($alreadyReserved) throw new \RuntimeException('One or more selected serials are already reserved.');
            if ($reservation->serial_id !== null) throw new \RuntimeException('This reservation already has a serial assignment.');

            $open = (int) round($reservation->open_quantity);
            $released = (float) $reservation->released_quantity;
            $base = [
                'company_id' => $reservation->company_id, 'product_id' => $reservation->product_id,
                'location_id' => $reservation->location_id, 'batch_id' => $reservation->batch_id,
                'sales_order_line_id' => $reservation->sales_order_line_id, 'source_type' => $reservation->source_type,
                'source_id' => $reservation->source_id, 'created_by' => $reservation->created_by, 'expires_at' => $reservation->expires_at,
                'status' => 'active', 'released_quantity' => 0,
            ];
            $assigned = [];
            $first = true;
            foreach ($serialIds as $serialId) {
                if ($first) {
                    $reservation->update(['quantity' => $released + 1, 'serial_id' => $serialId]);
                    $assigned[] = $reservation->fresh(['product', 'batch', 'serial', 'location']);
                    $first = false;
                } else {
                    $assigned[] = StockReservation::create($base + ['quantity' => 1, 'serial_id' => $serialId]);
                }
            }
            $unassigned = $open - count($serialIds);
            if ($unassigned > 0) StockReservation::create($base + ['quantity' => $unassigned, 'serial_id' => null]);
            return $assigned;
        });
    }

    /** @return Collection<int, array{serial_id:int|null,batch_id:int|null,quantity:float}> */
    public function serialAllocations(int $salesOrderLineId, float $quantity, ?int $locationId = null, ?int $productId = null, ?int $batchId = null): Collection
    {
        $remaining = (int) round($quantity);
        $allocations = collect();
        if ($remaining <= 0) return $allocations;
        $reservations = StockReservation::where('sales_order_line_id', $salesOrderLineId)->where('status', 'active')->whereNotNull('serial_id')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->when($locationId !== null, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('location_id')->orWhere('location_id', $locationId)))
            ->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int) floor($reservation->open_quantity));
            for ($index = 0; $index < $take; $index++) $allocations->push(['serial_id' => (int) $reservation->serial_id, 'batch_id' => $reservation->batch_id, 'quantity' => 1.0]);
            $remaining -= $take;
        }
        return $allocations;
    }

    public function assignBatch(StockReservation $reservation, int $batchId): StockReservation
    {
        return DB::transaction(function () use ($reservation, $batchId): StockReservation {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'active' || $reservation->open_quantity <= 0.000001) throw new \RuntimeException('Only open reservations can receive a batch assignment.');
            $product = Product::lockForUpdate()->findOrFail($reservation->product_id);
            if (!in_array($product->tracking_type, ['batch', 'lot'], true)) throw new \RuntimeException('Batch assignment is only available for batch- or lot-tracked products.');
            $batch = InventoryBatch::whereKey($batchId)->where('product_id', $product->id)->first();
            if (!$batch) throw new \RuntimeException('The selected batch does not belong to '.$product->name.'.');
            if ((float) $reservation->batch_id === (float) $batch->id) return $reservation->fresh(['product', 'batch', 'location']);

            $available = (float) InventoryCostLayer::where('product_id', $product->id)->where('batch_id', $batch->id)->where('remaining_quantity', '>', 0)
                ->when($reservation->location_id !== null, fn ($query) => $query->where('location_id', $reservation->location_id))
                ->sum('remaining_quantity');
            $reserved = (float) StockReservation::where('product_id', $product->id)->where('batch_id', $batch->id)->where('status', 'active')->where('id', '!=', $reservation->id)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->when($reservation->location_id !== null, fn ($query) => $query->where('location_id', $reservation->location_id))
                ->sum(DB::raw('quantity - released_quantity'));
            if ($available - $reserved + 0.000001 < $reservation->open_quantity) throw new \RuntimeException('Insufficient available stock in the selected batch for '.$product->name.'.');
            $reservation->update(['batch_id' => $batch->id]);
            return $reservation->fresh(['product', 'batch', 'location']);
        });
    }

    public function reassign(StockReservation $reservation, ?int $locationId): StockReservation
    {
        return DB::transaction(function () use ($reservation, $locationId): StockReservation {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'active' || $reservation->open_quantity <= 0.000001) throw new \RuntimeException('Only open reservations can be reassigned.');
            if ($locationId !== null) {
                $location = InventoryLocation::whereKey($locationId)->where('is_active', true)->firstOrFail();
                $product = Product::findOrFail($reservation->product_id);
                if ($location->id !== $reservation->location_id) {
                    $available = $reservation->batch_id
                        ? (float) InventoryCostLayer::where('product_id', $reservation->product_id)->where('batch_id', $reservation->batch_id)->where('location_id', $location->id)->sum('remaining_quantity') - (float) StockReservation::where('product_id', $reservation->product_id)->where('batch_id', $reservation->batch_id)->where('location_id', $location->id)->where('status', 'active')->sum(DB::raw('quantity - released_quantity'))
                        : app(InventoryAvailabilityService::class)->available($product, true, $location->id, $reservation->company_id);
                    if ($available < $reservation->open_quantity) throw new \RuntimeException('Insufficient available stock at the destination location for the reserved batch.');
                }
            }
            $reservation->update(['location_id' => $locationId]);
            return $reservation->fresh(['product', 'batch', 'location']);
        });
    }

    public function releaseReservation(StockReservation $reservation, float $quantity): StockReservation
    {
        return DB::transaction(function () use ($reservation, $quantity): StockReservation {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($quantity <= 0 || $quantity > $reservation->open_quantity + 0.000001) throw new \RuntimeException('Release quantity exceeds the open reservation balance.');
            $reservation->released_quantity = (float) $reservation->released_quantity + $quantity;
            if ($reservation->open_quantity <= 0.000001) $reservation->status = 'released';
            $reservation->save();
            if ($reservation->status === 'released') $this->restoreReleasedSerial($reservation);
            return $reservation->fresh(['product', 'batch', 'serial', 'location']);
        });
    }

    public function reservationExpiry(?int $companyId): ?\Carbon\Carbon
    {
        $days = (int) app(ErpSettingService::class)->get('reservation_expiry_days', 0, $companyId);
        return $days > 0 ? now()->addDays($days) : null;
    }
}
