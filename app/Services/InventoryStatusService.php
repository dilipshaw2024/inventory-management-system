<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryStatusBalance;
use App\Models\InventoryStatusTransfer;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryStatusService
{
    public function apply(InventoryStatusTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $product = Product::lockForUpdate()->findOrFail($transfer->product_id);
            if ($transfer->inspection_required && $transfer->inspection_status !== 'passed') {
                throw new \RuntimeException('This status transfer must pass inspection before approval.');
            }
            $quantity = (float) $transfer->quantity;
            if ($transfer->from_status === 'available') {
                if (app(InventoryAvailabilityService::class)->available($product, true, $transfer->location_id, $transfer->company_id) < $quantity) throw new \RuntimeException('Insufficient available stock at the selected location for '.$product->name.'.');
            } else {
                $balance = InventoryStatusBalance::where('product_id', $product->id)->where('location_id', $transfer->location_id)->where('status', $transfer->from_status)->lockForUpdate()->first();
                if (!$balance || (float) $balance->quantity < $quantity) throw new \RuntimeException('Insufficient '.$transfer->from_status.' stock for '.$product->name.'.');
                $balance->decrement('quantity', $quantity);
            }
            if ($transfer->to_status !== 'scrap') {
                $balance = InventoryStatusBalance::firstOrCreate(['product_id' => $product->id, 'location_id' => $transfer->location_id, 'status' => $transfer->to_status], ['quantity' => 0]);
                $balance->increment('quantity', $quantity);
            } else {
                // Scrapping removes physical stock regardless of its current
                // quality status, so keep the legacy physical quantity in sync.
                $product->quantity = (float) $product->quantity - $quantity;
                $product->save();
            }
            // A move between two unavailable statuses changes disposition, not
            // physical availability. Posting a quarantine_out movement here
            // would incorrectly increase the location's available balance.
            if ($this->changesPhysicalStock($transfer)) {
                $movementType = $transfer->to_status === 'scrap'
                    ? 'scrap'
                    : ($transfer->from_status === 'available' ? 'quarantine_in' : 'quarantine_out');
                app(InventoryLedgerService::class)->post($product->id, $movementType, $quantity, (float) ($product->purchase_price ?? 0), $transfer->location_id, $transfer, $transfer->reason);
            }
        });
    }

    public function changesPhysicalStock(InventoryStatusTransfer $transfer): bool
    {
        return $transfer->to_status === 'scrap'
            || $transfer->from_status === 'available'
            || $transfer->to_status === 'available';
    }

    public function inspect(InventoryStatusTransfer $transfer, string $status, string $notes, ?int $actorId = null): InventoryStatusTransfer
    {
        return DB::transaction(function () use ($transfer, $status, $notes, $actorId): InventoryStatusTransfer {
            $locked = InventoryStatusTransfer::where('company_id', $transfer->company_id)->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== 'pending' || !$locked->inspection_required || $locked->inspection_status !== 'pending') {
                throw new \RuntimeException('Only pending status transfers awaiting inspection can be inspected.');
            }
            app(ApprovalGuard::class)->assertDifferent($locked);
            $before = $locked->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
            $locked->update(['inspection_status' => $status, 'inspection_notes' => $notes, 'inspected_by' => $actorId, 'inspected_at' => now()]);
            app(AuditService::class)->record('inventory_status_transfer.inspected', $locked, $before, $locked->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
            return $locked->fresh(['product', 'location', 'inspector']);
        });
    }
}
