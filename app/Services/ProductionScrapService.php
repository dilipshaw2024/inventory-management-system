<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductionScrapRecord;
use Illuminate\Support\Facades\DB;

class ProductionScrapService
{
    public function approve(int $id): ProductionScrapRecord
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(ProductionScrapRecord::class, $id);
        return DB::transaction(function () use ($id): ProductionScrapRecord {
            $scrap = ProductionScrapRecord::with('productionOrder')->lockForUpdate()->findOrFail($id);
            if ($scrap->status !== 'pending') throw new \RuntimeException('This production scrap record has already been processed.');
            if (!in_array($scrap->productionOrder->status, ['released', 'in_progress', 'paused', 'completed'], true)) throw new \RuntimeException('Scrap can only be recorded against an open or completed production order.');
            app(ApprovalGuard::class)->assertDifferent($scrap);
            $product = Product::where(function ($query) use ($scrap): void { $query->where('company_id', $scrap->company_id)->orWhereNull('company_id'); })->lockForUpdate()->findOrFail($scrap->product_id);
            app(ProductLifecycleService::class)->assertStockManaged($product);
            $quantity = (float) $scrap->quantity;
            if (app(InventoryAvailabilityService::class)->available($product, true, $scrap->location_id, $scrap->company_id) < $quantity) throw new \RuntimeException('Insufficient available stock for production scrap of '.$product->name.'.');
            $batch = null;
            if ($scrap->batch_no) {
                $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $scrap->batch_no)->lockForUpdate()->first();
                if (!$batch) throw new \RuntimeException('Batch '.$scrap->batch_no.' was not found for '.$product->name.'.');
                if ($batch->location_id && $scrap->location_id && (int) $batch->location_id !== (int) $scrap->location_id) throw new \RuntimeException('Selected batch is not held at the scrap location.');
            }
            $serialNumbers = $scrap->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $scrap->serial_numbers)))) : [];
            $issuedSerials = collect();
            if ($product->tracking_type === 'serial') {
                if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Scrap serial count must equal quantity for '.$product->name.'.');
                $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $scrap->location_id, $batch?->id);
            }
            $product->quantity = (float) $product->quantity - $quantity;
            $product->save();
            $unitCost = $scrap->unit_cost !== null ? (float) $scrap->unit_cost : (float) ($product->purchase_price ?? 0);
            if ($issuedSerials->isNotEmpty()) {
                foreach ($issuedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'scrap', 1, $unitCost, $scrap->location_id, $scrap, $scrap->reason, null, $serial->batch_id ?: $batch?->id, $serial->id);
            } else {
                app(InventoryLedgerService::class)->post($product->id, 'scrap', $quantity, $unitCost, $scrap->location_id, $scrap, $scrap->reason, null, $batch?->id);
            }
            $recoveryQuantity = (float) ($scrap->recovery_quantity ?? 0);
            if ($scrap->recovery_product_id && (int) $scrap->recovery_product_id === (int) $scrap->product_id) throw new \RuntimeException('Recovery product must differ from the scrapped product.');
            if ($scrap->recovery_product_id && $recoveryQuantity <= 0.000001) throw new \RuntimeException('Recovery quantity must be greater than zero when a recovery product is selected.');
            if (!$scrap->recovery_product_id && $recoveryQuantity > 0.000001) throw new \RuntimeException('A recovery product is required when recovery quantity is provided.');
            if ($scrap->recovery_product_id) {
                $recovery = Product::where(function ($query) use ($scrap): void { $query->where('company_id', $scrap->company_id)->orWhereNull('company_id'); })->lockForUpdate()->findOrFail($scrap->recovery_product_id);
                app(ProductLifecycleService::class)->assertStockManaged($recovery);
                $recoveryUnitCost = $scrap->recovery_unit_cost !== null ? (float) $scrap->recovery_unit_cost : $unitCost;
                $recovery->quantity = (float) $recovery->quantity + $recoveryQuantity;
                $recovery->purchase_price = $recoveryUnitCost;
                $recovery->save();
                app(InventoryLedgerService::class)->post($recovery->id, 'receipt', $recoveryQuantity, $recoveryUnitCost, $scrap->location_id, $scrap, 'Production scrap recovery');
            }
            $scrap->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(AuditService::class)->record('production_scrap.approved', $scrap, ['status' => 'pending'], ['status' => 'approved', 'quantity' => $quantity, 'unit_cost' => $unitCost, 'recovery_product_id' => $scrap->recovery_product_id, 'recovery_quantity' => $recoveryQuantity]);
            return $scrap->fresh(['productionOrder', 'product', 'recoveryProduct', 'location']);
        });
    }

    public function reject(int $id, string $reason): ProductionScrapRecord
    {
        return DB::transaction(function () use ($id, $reason): ProductionScrapRecord {
            $scrap = ProductionScrapRecord::lockForUpdate()->findOrFail($id);
            if ($scrap->status !== 'pending') throw new \RuntimeException('This production scrap record has already been processed.');
            $scrap->update(['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('production_scrap.rejected', $scrap, ['status' => 'pending'], ['status' => 'rejected', 'rejection_reason' => $reason]);
            return $scrap->fresh(['productionOrder', 'product', 'location']);
        });
    }
}
