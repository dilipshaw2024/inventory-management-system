<?php

namespace App\Services;

use App\Models\MaintenanceOrder;
use App\Models\MaintenancePart;
use App\Models\MaintenancePartReturn;
use App\Models\Product;
use Illuminate\Support\Collection;

class MaintenancePartReturnService
{
    public function returnToStock(
        MaintenanceOrder $order,
        MaintenancePart $part,
        float $quantity,
        string $reason,
        array $serialNumbers = []
    ): MaintenancePartReturn {
        if ((int) $part->maintenance_order_id !== (int) $order->id) throw new \RuntimeException('The selected part does not belong to this maintenance order.');
        if ($quantity <= 0.000001) throw new \RuntimeException('Return quantity must be greater than zero.');
        $open = (float) $part->quantity - (float) $part->returned_quantity;
        if ($quantity > $open + 0.000001) throw new \RuntimeException('Return quantity exceeds the unreturned maintenance-part quantity.');
        $serialNumbers = array_values(array_unique(array_filter(array_map('trim', $serialNumbers))));
        $product = Product::lockForUpdate()->findOrFail($part->product_id);
        $serials = collect();
        if ($product->tracking_type === 'serial') {
            if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal returned quantity for '.$product->name.'.');
            $serials = app(SerialLifecycleService::class)->returnToStock($product, $serialNumbers, $part->location_id, $part->batch_id);
        }
        $cost = (float) $part->unit_cost;
        if ($product->tracking_type === 'serial') {
            foreach ($serials as $serial) {
                $movement = app(InventoryLedgerService::class)->post($product->id, 'return_in', 1, $cost, $part->location_id, $order, 'Maintenance spare-part return', null, $serial->batch_id ?: $part->batch_id, $serial->id);
                $cost = (float) $movement->unit_cost;
            }
        } else {
            $product->quantity = (float) $product->quantity + $quantity;
            $product->save();
            app(InventoryLedgerService::class)->post($product->id, 'return_in', $quantity, $cost, $part->location_id, $order, 'Maintenance spare-part return', null, $part->batch_id);
        }
        if ($product->tracking_type === 'serial') {
            $product->quantity = (float) $product->quantity + $quantity;
            $product->save();
        }
        $part->returned_quantity = (float) $part->returned_quantity + $quantity;
        $part->save();
        $return = MaintenancePartReturn::create([
            'company_id' => $order->company_id ?: auth()->user()?->company_id,
            'maintenance_order_id' => $order->id, 'maintenance_part_id' => $part->id, 'product_id' => $product->id,
            'location_id' => $part->location_id, 'batch_id' => $part->batch_id, 'quantity' => $quantity,
            'serial_numbers' => $serials->pluck('serial_no')->implode(','), 'reason' => $reason, 'created_by' => auth()->id(),
        ]);
        app(AuditService::class)->record('maintenance_part.returned', $return, null, $return->toArray());
        return $return;
    }
}
