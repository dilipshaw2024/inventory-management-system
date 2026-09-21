<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function release(int $id, ?int $companyId = null, ?int $actorId = null): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id, $companyId, $actorId);
        return DB::transaction(function () use ($id, $companyId, $actorId): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::with(['bom.lines', 'bom.byproducts']), $companyId)->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'draft') throw new \RuntimeException('This production order cannot be released again.');
            app(ApprovalGuard::class)->assertDifferent($order, $actorId, $companyId);
            $requirements = $order->bom_snapshot ? app(BomExplosionService::class)->leafRequirementsFromSnapshot($order->bom_snapshot, (float) $order->planned_quantity) : app(BomExplosionService::class)->leafRequirements($order->bom, (float) $order->planned_quantity, $order->company_id, $order->planned_date?->toDateString());
            foreach ($requirements as $componentId => $required) {
                $component = $this->companyScope(Product::query(), $order->company_id)->lockForUpdate()->findOrFail($componentId);
                app(ProductLifecycleService::class)->assertStockManaged($component);
                if (app(InventoryAvailabilityService::class)->available($component, true, $order->location_id, $order->company_id) < $required) throw new \RuntimeException('Insufficient available component stock for '.$component->name.'.');
            }
            $output = $this->companyScope(Product::query(), $order->company_id)->lockForUpdate()->findOrFail($order->product_id);
            app(ProductLifecycleService::class)->assertStockManaged($output);
            app(StockReservationService::class)->reserveProductionOrder($order, $requirements);
            app(ProductionOperationService::class)->initialize($order);
            $order->update(['status' => 'released', 'approved_by' => $actorId ?? auth()->id(), 'approved_at' => now()]);
            app(AuditService::class)->record('production_order.released', $order, ['status' => 'draft'], ['status' => 'released']);
            return $order;
        });
    }

    public function complete(int $id, ?float $producedQuantity = null): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id);
        return DB::transaction(function () use ($id, $producedQuantity): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::with(['bom.lines', 'bom.byproducts', 'operations.workCenter', 'operations.routingOperation']))->lockForUpdate()->findOrFail($id);
            if (!in_array($order->status, ['released', 'in_progress'], true)) throw new \RuntimeException('Only released production orders can be completed.');
            app(ApprovalGuard::class)->assertDifferent($order);
            app(ProductionOperationService::class)->assertReadyForCompletion($order);
            $remainingPlanned = (float) $order->planned_quantity - (float) $order->completed_quantity;
            $quantity = $producedQuantity ?? $remainingPlanned;
            if ($quantity <= 0.000001 || $quantity > $remainingPlanned + 0.000001) throw new \RuntimeException('Produced quantity must be greater than zero and no more than the remaining planned quantity.');
            if ($order->product->tracking_type === 'serial' && abs($quantity - $remainingPlanned) > 0.000001) throw new \RuntimeException('Serial-tracked production orders must be completed in one receipt.');
            $requirements = $order->bom_snapshot ? app(BomExplosionService::class)->leafRequirementsFromSnapshot($order->bom_snapshot, $quantity) : app(BomExplosionService::class)->leafRequirements($order->bom, $quantity, $order->company_id, $order->planned_date?->toDateString());
            $cost = 0;
            $materialCost = 0.0;
            foreach ($requirements as $componentId => $required) {
                $component = $this->companyScope(Product::query(), $order->company_id)->lockForUpdate()->findOrFail($componentId);
                app(ProductLifecycleService::class)->assertStockManaged($component);
                $reservations = StockReservation::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('product_id', $component->id)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('id')->lockForUpdate()->get();
                app(StockReservationService::class)->releaseForSourceRequirements($order, [$component->id => $required]);
                if (app(InventoryAvailabilityService::class)->available($component, true, $order->location_id, $order->company_id) < $required) throw new \RuntimeException('Insufficient available component stock for '.$component->name.'.');
                $component->quantity = (float) $component->quantity - $required;
                $component->save();
                $remaining = (float) $required;
                $batchAllocations = collect();
                foreach ($reservations as $reservation) {
                    if ($remaining <= 0.000001) break;
                    $allocated = min($remaining, (float) $reservation->open_quantity);
                    if ($allocated <= 0.000001) continue;
                    $batchAllocations->push(['batch_id' => $reservation->batch_id, 'quantity' => $allocated]);
                    $remaining -= $allocated;
                }
                if ($remaining > 0.000001) $batchAllocations->push(['batch_id' => null, 'quantity' => $remaining]);
                if ($component->tracking_type === 'serial') {
                    foreach ($batchAllocations as $allocation) {
                        foreach (app(SerialLifecycleService::class)->issue($component, (float) $allocation['quantity'], $order->location_id, $allocation['batch_id']) as $serial) {
                            $movement = app(InventoryLedgerService::class)->post($component->id, 'issue', 1, (float) ($component->purchase_price ?? 0), $order->location_id, $order, 'Production material consumption', null, $serial->batch_id ?: $allocation['batch_id'], $serial->id);
                            $lineCost = (float) ($movement->unit_cost ?? 0);
                            $cost += $lineCost;
                            $materialCost += $lineCost;
                        }
                    }
                } elseif (in_array($component->tracking_type, ['batch', 'lot'], true)) {
                    foreach ($batchAllocations as $allocation) {
                        $movement = app(InventoryLedgerService::class)->post($component->id, 'issue', (float) $allocation['quantity'], (float) ($component->purchase_price ?? 0), $order->location_id, $order, 'Production material consumption', null, $allocation['batch_id']);
                        $lineCost = (float) $allocation['quantity'] * (float) ($movement->unit_cost ?? 0);
                        $cost += $lineCost;
                        $materialCost += $lineCost;
                    }
                } else {
                    $movement = app(InventoryLedgerService::class)->post($component->id, 'issue', $required, (float) ($component->purchase_price ?? 0), $order->location_id, $order, 'Production material consumption');
                    $lineCost = $required * (float) ($movement->unit_cost ?? 0);
                    $cost += $lineCost;
                    $materialCost += $lineCost;
                }
            }
            $operationCost = app(ProductionCostService::class)->operationCost($order->operations, $quantity, (float) $order->planned_quantity);
            $cost += $operationCost;
            $finished = $this->companyScope(Product::query(), $order->company_id)->lockForUpdate()->findOrFail($order->product_id);
            app(ProductLifecycleService::class)->assertStockManaged($finished);
            $outputQuantityPerBom = (float) ($order->bom_snapshot['output_quantity'] ?? $order->bom->output_quantity);
            $factor = $quantity / max($outputQuantityPerBom, 0.000001);
            $byproductValue = 0;
            $byproducts = $order->bom_snapshot ? collect($order->bom_snapshot['byproducts'] ?? [])->map(fn (array $byproduct): object => (object) $byproduct) : $order->bom->byproducts;
            foreach ($byproducts as $byproduct) {
                $outputQuantity = (float) $byproduct->quantity * $factor;
                $value = $cost * ((float) $byproduct->cost_share_percent / 100);
                $byproductProduct = $this->companyScope(Product::query(), $order->company_id)->lockForUpdate()->findOrFail($byproduct->product_id);
                app(ProductLifecycleService::class)->assertStockManaged($byproductProduct);
                $byproductProduct->quantity = (float) $byproductProduct->quantity + $outputQuantity;
                $byproductProduct->purchase_price = $outputQuantity > 0 ? $value / $outputQuantity : $byproductProduct->purchase_price;
                $byproductProduct->save();
                app(InventoryLedgerService::class)->post($byproductProduct->id, 'receipt', $outputQuantity, (float) $byproductProduct->purchase_price, $order->location_id, $order, 'Production by-product');
                $byproductValue += $value;
            }
            $finished->quantity = (float) $finished->quantity + $quantity;
            $finished->purchase_price = $quantity > 0 ? max(0, $cost - $byproductValue) / $quantity : $finished->purchase_price;
            $finished->save();
            $batch = $order->output_batch_no ? InventoryBatch::firstOrCreate(['product_id' => $finished->id, 'batch_no' => $order->output_batch_no], ['location_id' => $order->location_id, 'manufacturing_date' => $order->output_manufacturing_date, 'expiry_date' => $order->output_expiry_date, 'best_before_date' => $order->output_best_before_date, 'warranty_until' => $order->output_warranty_until]) : null;
            $serialNumbers = $order->output_serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $order->output_serial_numbers)))) : [];
            if ($finished->tracking_type === 'serial') {
                if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Output serial count must equal production quantity for '.$finished->name.'.');
                foreach ($serialNumbers as $serialNo) {
                    $serial = app(SerialLifecycleService::class)->receive($finished, $serialNo, $order->location_id, $batch?->id, $order->output_warranty_until);
                    app(InventoryLedgerService::class)->post($finished->id, 'receipt', 1, (float) $finished->purchase_price, $order->location_id, $order, 'Production finished goods', null, $batch?->id, $serial->id);
                }
            } else app(InventoryLedgerService::class)->post($finished->id, 'receipt', $quantity, (float) $finished->purchase_price, $order->location_id, $order, 'Production finished goods', null, $batch?->id);
            $completedQuantity = (float) $order->completed_quantity + $quantity;
            $status = $completedQuantity + 0.000001 >= (float) $order->planned_quantity ? 'completed' : 'in_progress';
            $netProductionCost = max(0, $cost - $byproductValue);
            $order->update(['status' => $status, 'completed_quantity' => $completedQuantity, 'material_cost' => (float) $order->material_cost + $materialCost, 'operation_cost' => (float) $order->operation_cost + $operationCost, 'byproduct_cost' => (float) $order->byproduct_cost + $byproductValue, 'production_cost' => (float) $order->production_cost + $netProductionCost, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(AuditService::class)->record('production_order.completed', $order, ['status' => 'released', 'completed_quantity' => (float) $order->completed_quantity - $quantity], ['status' => $status, 'completed_quantity' => $completedQuantity, 'receipt_quantity' => $quantity, 'operation_cost' => $operationCost]);
            return $order;
        });
    }

    public function pause(int $id, string $reason): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id);
        return DB::transaction(function () use ($id, $reason): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::query())->lockForUpdate()->findOrFail($id);
            if (!in_array($order->status, ['released', 'in_progress'], true)) throw new \RuntimeException('Only released or in-progress production orders can be paused.');
            app(ApprovalGuard::class)->assertDifferent($order);
            $before = $order->only(['status', 'pause_reason', 'paused_by', 'paused_at', 'paused_from_status']);
            $order->update(['status' => 'paused', 'pause_reason' => $reason, 'paused_by' => auth()->id(), 'paused_at' => now(), 'paused_from_status' => $before['status']]);
            app(AuditService::class)->record('production_order.paused', $order, $before, $order->fresh()->only(['status', 'pause_reason', 'paused_by', 'paused_at', 'paused_from_status']));
            return $order->fresh();
        });
    }

    public function resume(int $id): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id);
        return DB::transaction(function () use ($id): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::query())->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'paused') throw new \RuntimeException('Only paused production orders can be resumed.');
            app(ApprovalGuard::class)->assertDifferent($order);
            $resumeStatus = in_array($order->paused_from_status, ['released', 'in_progress'], true) ? $order->paused_from_status : 'released';
            $before = $order->only(['status', 'pause_reason', 'paused_by', 'paused_at', 'paused_from_status']);
            $order->update(['status' => $resumeStatus, 'pause_reason' => null, 'paused_by' => null, 'paused_at' => null, 'paused_from_status' => null]);
            app(AuditService::class)->record('production_order.resumed', $order, $before, $order->fresh()->only(['status', 'pause_reason', 'paused_by', 'paused_at', 'paused_from_status']));
            return $order->fresh();
        });
    }

    public function close(int $id, string $reason): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id);
        return DB::transaction(function () use ($id, $reason): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::query())->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'completed') throw new \RuntimeException('Only completed production orders can be closed.');
            app(ApprovalGuard::class)->assertDifferent($order);
            $before = $order->only(['status', 'close_reason', 'closed_by', 'closed_at']);
            $order->update(['status' => 'closed', 'close_reason' => $reason, 'closed_by' => auth()->id(), 'closed_at' => now()]);
            app(AuditService::class)->record('production_order.closed', $order, $before, $order->fresh()->only(['status', 'close_reason', 'closed_by', 'closed_at']));
            return $order->fresh();
        });
    }

    public function cancel(int $id, string $reason): ProductionOrder
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionOrder::class, $id);
        return DB::transaction(function () use ($id, $reason): ProductionOrder {
            $order = $this->companyScope(ProductionOrder::query())->lockForUpdate()->findOrFail($id);
            if (!in_array($order->status, ['draft', 'released', 'in_progress', 'paused'], true)) throw new \RuntimeException('Only open production orders can be cancelled.');
            app(ApprovalGuard::class)->assertDifferent($order);
            $before = $order->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
            app(StockReservationService::class)->releaseForSource($order);
            $order->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            app(AuditService::class)->record('production_order.cancelled', $order, $before, $order->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']));
            return $order->fresh();
        });
    }

    private function companyScope($query, ?int $companyId = null)
    {
        $companyId ??= auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
