<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentApprovalService
{
    public function approve(InventoryAdjustment $adjustment, ?int $companyId, ?int $approverId): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $companyId, $approverId): InventoryAdjustment {
            $adjustment = $this->scope(InventoryAdjustment::with('lines'), $companyId)
                ->lockForUpdate()->findOrFail($adjustment->id);

            if ($adjustment->status !== 'pending') {
                throw new \RuntimeException('This adjustment has already been processed.');
            }

            app(ApprovalGuard::class)->assertDifferent($adjustment);

            foreach ($adjustment->lines as $line) {
                $product = $this->scope(Product::query(), $companyId)->lockForUpdate()->findOrFail($line->product_id);
                $quantity = (float) $line->quantity;

                if ($line->direction === 'out'
                    && app(InventoryAvailabilityService::class)->available($product, true, $line->location_id, $adjustment->company_id) < $quantity) {
                    throw new \RuntimeException('Insufficient stock for '.$product->name.'.');
                }

                $batch = null;
                $batchTracked = in_array($product->tracking_type, ['batch', 'lot'], true);
                if ($line->batch_no) {
                    $batchQuery = InventoryBatch::where('product_id', $product->id)
                        ->where('batch_no', $line->batch_no);
                    $batch = $line->direction === 'in'
                        ? $batchQuery->first()
                        : $batchQuery->lockForUpdate()->first();

                    if ($line->direction === 'in' && !$batch) {
                        $batch = InventoryBatch::create([
                            'product_id' => $product->id,
                            'batch_no' => $line->batch_no,
                            'location_id' => $line->location_id,
                            'manufacturing_date' => $line->manufacturing_date,
                            'expiry_date' => $line->expiry_date,
                            'best_before_date' => $line->best_before_date,
                            'warranty_until' => $line->warranty_until,
                        ]);
                    }
                }

                if ($batchTracked && $line->direction === 'out') {
                    if (!$batch) {
                        throw new \RuntimeException('A valid batch/lot number is required for '.$product->name.'.');
                    }
                    $batchBalance = $this->scope(InventoryMovement::query(), $companyId)
                        ->where('product_id', $product->id)->where('batch_id', $batch->id)
                        ->when($line->location_id, fn ($query) => $query->where('location_id', $line->location_id))
                        ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap') THEN -quantity ELSE 0 END), 0) AS balance")
                        ->value('balance');
                    if ((float) $batchBalance < $quantity) {
                        throw new \RuntimeException('Insufficient stock in batch '.$batch->batch_no.' for '.$product->name.'.');
                    }
                }

                $serialNumbers = $line->serial_numbers
                    ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers))))
                    : [];
                $serials = [];

                if ($product->tracking_type === 'serial' && $line->direction === 'in'
                    && $adjustment->reason_code === 'opening_stock' && !$serialNumbers) {
                    throw new \RuntimeException('Serial numbers are required for opening stock of '.$product->name.'.');
                }
                if ($batchTracked && $line->direction === 'in'
                    && $adjustment->reason_code === 'opening_stock' && !$line->batch_no) {
                    throw new \RuntimeException('Batch/lot number is required for opening stock of '.$product->name.'.');
                }
                if ($product->tracking_type === 'serial' && $line->direction === 'out') {
                    if (count($serialNumbers) !== (int) round($quantity)) {
                        throw new \RuntimeException('Serial numbers are required and must equal quantity for '.$product->name.'.');
                    }
                    $serials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $line->location_id, $batch?->id);
                }
                if ($product->tracking_type === 'serial' && $line->direction === 'in' && $serialNumbers) {
                    if (count($serialNumbers) !== (int) round($quantity)) {
                        throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                    }
                    foreach ($serialNumbers as $serialNo) {
                        $serials[] = app(SerialLifecycleService::class)->receive($product, $serialNo, $line->location_id, $batch?->id, $line->warranty_until);
                    }
                }

                $product->quantity = (float) $product->quantity + ($line->direction === 'in' ? $quantity : -$quantity);
                $product->save();
                $movementType = $adjustment->reason_code === 'opening_stock'
                    ? 'opening'
                    : ($line->direction === 'in' ? 'adjustment_in' : 'adjustment_out');
                if ($serials) {
                    foreach ($serials as $serial) {
                        app(InventoryLedgerService::class)->post($product->id, $movementType, 1, $line->unit_cost ? (float) $line->unit_cost : null, $line->location_id, $adjustment, $adjustment->reason_code, null, $batch?->id, $serial->id);
                    }
                } else {
                    app(InventoryLedgerService::class)->post($product->id, $movementType, $quantity, $line->unit_cost ? (float) $line->unit_cost : null, $line->location_id, $adjustment, $adjustment->reason_code, null, $batch?->id);
                }
            }

            $adjustment->update(['status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now()]);
            app(AuditService::class)->record('inventory_adjustment.approved', $adjustment, ['status' => 'pending'], ['status' => 'approved']);
            return $adjustment->fresh('lines');
        });
    }

    public function reject(InventoryAdjustment $adjustment, ?int $companyId, ?int $rejectorId, string $reason): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $companyId, $rejectorId, $reason): InventoryAdjustment {
            $adjustment = $this->scope(InventoryAdjustment::query(), $companyId)->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== 'pending') {
                throw new \RuntimeException('This adjustment has already been processed.');
            }
            app(ApprovalGuard::class)->assertDifferent($adjustment);
            $before = $adjustment->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $adjustment->update(['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_by' => $rejectorId, 'rejected_at' => now()]);
            app(AuditService::class)->record('inventory_adjustment.rejected', $adjustment, $before, $adjustment->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $adjustment->fresh('lines');
        });
    }

    private function scope($query, ?int $companyId)
    {
        return $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
