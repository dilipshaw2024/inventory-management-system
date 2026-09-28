<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventoryDocument;
use App\Models\InventoryExpiryOverrideRequest;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryDocumentApprovalService
{
    public function approve(InventoryDocument $source, ?User $actor = null): InventoryDocument
    {
        $actorId = $actor?->id;
        return DB::transaction(function () use ($source, $actorId): InventoryDocument {
            $document = InventoryDocument::with('lines')
                ->where('company_id', $source->company_id)
                ->lockForUpdate()->findOrFail($source->id);
            if ($document->status !== 'pending') throw new \RuntimeException('This inventory document has already been processed.');
            $reversalSource = $document->reversedDocument()->with('productionOrder')->first();
            if ($document->reversal_of_id && (!$reversalSource || $reversalSource->status !== 'approved' || $reversalSource->document_type !== 'issue' || $document->document_type !== 'receipt')) {
                throw new \RuntimeException('This reversal receipt no longer has a valid approved material-issue source.');
            }
            if ($document->production_order_id) {
                $productionOrder = \App\Models\ProductionOrder::where('company_id', $document->company_id)->findOrFail($document->production_order_id);
                if ($document->document_type !== 'issue' || in_array($productionOrder->status, ['cancelled', 'closed', 'completed', 'paused'], true)) {
                    throw new \RuntimeException('A material issue can only be posted against a released or in-progress production order.');
                }
                if ($productionOrder->location_id && $document->location_id && (int) $productionOrder->location_id !== (int) $document->location_id) {
                    throw new \RuntimeException('The material issue location must match the production order location.');
                }
                $requirements = $productionOrder->bom_snapshot
                    ? app(BomExplosionService::class)->leafRequirementsFromSnapshot($productionOrder->bom_snapshot, (float) $productionOrder->planned_quantity)
                    : ($productionOrder->bom ? app(BomExplosionService::class)->leafRequirements($productionOrder->bom, (float) $productionOrder->planned_quantity, $productionOrder->company_id, $productionOrder->planned_date?->toDateString()) : []);
                foreach ($document->lines as $materialLine) {
                    if (!array_key_exists((int) $materialLine->product_id, $requirements)) throw new \RuntimeException('Product '.$materialLine->product_id.' is not an effective BOM component for this production order.');
                }
            }
            if ($document->document_type === 'receipt' && $document->inspection_status !== 'not_required' && $document->inspection_status !== 'passed') {
                throw new \RuntimeException('This receipt must pass inspection before stock can be posted.');
            }
            app(ApprovalGuard::class)->assertDifferent($document);

            foreach ($document->lines as $line) {
                $product = Product::where(function ($query) use ($document): void {
                    $query->where('company_id', $document->company_id)->orWhereNull('company_id');
                })->lockForUpdate()->findOrFail($line->product_id);
                $quantity = (float) $line->quantity;
                if ($document->document_type === 'issue' && app(InventoryAvailabilityService::class)->available($product, false, $document->location_id, $document->company_id) < $quantity) {
                    throw new \RuntimeException('Insufficient available stock at the selected location for '.$product->name.'.');
                }
                if ($document->production_order_id) {
                    $reserved = (float) \App\Models\StockReservation::where('source_type', $productionOrder->getMorphClass())
                        ->where('source_id', $productionOrder->id)->where('product_id', $product->id)->where('status', 'active')
                        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->sum(DB::raw('quantity - released_quantity'));
                    if ($reserved + 0.000001 < $quantity) {
                        throw new \RuntimeException('Material issue exceeds the open production reservation for '.$product->name.'.');
                    }
                }

                $batchAllocations = collect($line->batch_allocations ?: []);
                $multiBatchIssue = $document->document_type === 'issue' && $batchAllocations->isNotEmpty();
                if ($multiBatchIssue && abs((float) $batchAllocations->sum(fn (array $allocation): float => (float) ($allocation['quantity'] ?? 0)) - $quantity) > 0.000001) {
                    throw new \RuntimeException('Batch allocation quantities must equal the issue quantity for '.$product->name.'.');
                }
                if ($multiBatchIssue && $product->tracking_type === 'serial' && $line->serial_numbers) {
                    throw new \RuntimeException('Serial numbers must be supplied inside each batch allocation for '.$product->name.'.');
                }
                $automaticBatchAllocations = (!$multiBatchIssue && $document->document_type === 'issue' && !$line->batch_no)
                    ? $this->automaticBatchAllocations($product, $quantity, $document->location_id, $document->company_id, $document)
                    : collect();
                if ($automaticBatchAllocations->isNotEmpty()) {
                    $line->update(['batch_allocations' => $automaticBatchAllocations->all()]);
                    $batchAllocations = $automaticBatchAllocations;
                }
                $batch = null;
                if (!$multiBatchIssue && $line->batch_no && $document->document_type === 'receipt') {
                    $batch = InventoryBatch::firstOrCreate(
                        ['product_id' => $product->id, 'batch_no' => $line->batch_no],
                        ['location_id' => $document->location_id, 'manufacturing_date' => $line->manufacturing_date, 'expiry_date' => $line->expiry_date, 'best_before_date' => $line->best_before_date, 'warranty_until' => $line->warranty_until]
                    );
                } elseif (!$multiBatchIssue && $line->batch_no && $document->document_type === 'issue') {
                    $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $line->batch_no)->lockForUpdate()->first();
                    if (!$batch) throw new \RuntimeException('Batch '.$line->batch_no.' was not found for '.$product->name.'.');
                    if ($batch->location_id && $document->location_id && (int) $batch->location_id !== (int) $document->location_id) throw new \RuntimeException('Selected batch is not held at the selected location.');
                }

                $serialNumbers = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers)))) : [];
                $issuedSerials = collect();
                $receivedSerials = collect();
                if (!$multiBatchIssue && $product->tracking_type === 'serial') {
                    if ($document->document_type === 'receipt') {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                        foreach ($serialNumbers as $serialNo) $receivedSerials->push(app(SerialLifecycleService::class)->receive($product, $serialNo, $document->location_id, $batch?->id, $line->warranty_until));
                    } elseif ($serialNumbers) {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Selected serial count must equal quantity for '.$product->name.'.');
                        $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $document->location_id, $batch?->id);
                    } else {
                        $issuedSerials = app(SerialLifecycleService::class)->issue($product, $quantity, $document->location_id, $batch?->id);
                    }
                }

                $product->quantity = (float) $product->quantity + ($document->document_type === 'receipt' ? $quantity : -$quantity);
                $product->save();
                $unitCost = $line->unit_cost !== null ? (float) $line->unit_cost : (float) ($product->purchase_price ?? 0);
                $departmentId = $line->department_id ?: $document->department_id;
                $costCenterId = $line->cost_center_id ?: $document->cost_center_id;
                if ($multiBatchIssue || $automaticBatchAllocations->isNotEmpty()) {
                    foreach ($batchAllocations as $allocation) {
                        $allocatedQuantity = (float) $allocation['quantity'];
                        $allocatedBatch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $allocation['batch_no'])->lockForUpdate()->first();
                        if (!$allocatedBatch) throw new \RuntimeException('Batch '.$allocation['batch_no'].' was not found for '.$product->name.'.');
                        if ($allocatedBatch->location_id && $document->location_id && (int) $allocatedBatch->location_id !== (int) $document->location_id) throw new \RuntimeException('Selected batch is not held at the selected location.');
                        $batchAvailable = (float) InventoryCostLayer::where('product_id', $product->id)->where('batch_id', $allocatedBatch->id)->where('remaining_quantity', '>', 0)->when($document->location_id, fn ($query) => $query->where('location_id', $document->location_id))->sum('remaining_quantity');
                        if ($batchAvailable + 0.000001 < $allocatedQuantity) throw new \RuntimeException('Insufficient stock in batch '.$allocatedBatch->batch_no.' for '.$product->name.'.');
                        $allocationSerials = array_values(array_filter(array_map('trim', $allocation['serial_numbers'] ?? [])));
                        if ($product->tracking_type === 'serial') {
                            if (count($allocationSerials) !== (int) round($allocatedQuantity)) throw new \RuntimeException('Serial count must equal the batch allocation quantity for '.$product->name.'.');
                            foreach (app(SerialLifecycleService::class)->issueSpecific($product, $allocationSerials, $document->location_id, $allocatedBatch->id) as $serial) app(InventoryLedgerService::class)->post($product->id, 'issue', 1, $unitCost, $document->location_id, $document, $document->description, null, $allocatedBatch->id, $serial->id, $departmentId, $costCenterId);
                        } else {
                            app(InventoryLedgerService::class)->post($product->id, 'issue', $allocatedQuantity, $unitCost, $document->location_id, $document, $document->description, null, $allocatedBatch->id, null, $departmentId, $costCenterId);
                        }
                    }
                } elseif ($document->document_type === 'issue' && $issuedSerials->isNotEmpty()) {
                    foreach ($issuedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'issue', 1, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, $serial->id, $departmentId, $costCenterId);
                } elseif ($document->document_type === 'receipt' && $receivedSerials->isNotEmpty()) {
                    foreach ($receivedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'receipt', 1, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, $serial->id, $departmentId, $costCenterId);
                } else {
                    app(InventoryLedgerService::class)->post($product->id, $document->document_type, $quantity, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, null, $departmentId, $costCenterId);
                }
                if ($document->production_order_id) {
                    app(StockReservationService::class)->releaseForSourceRequirements($productionOrder, [$product->id => $quantity]);
                }
            }
            if ($reversalSource?->production_order_id && $reversalSource->productionOrder) {
                $restore = $document->lines->groupBy('product_id')->map(fn ($lines): float => (float) $lines->sum('quantity'))->all();
                app(StockReservationService::class)->restoreForSourceRequirements($reversalSource->productionOrder, $restore);
            }
            $document->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            InventoryExpiryOverrideRequest::where('company_id', $document->company_id)
                ->where('inventory_document_id', $document->id)->where('status', 'approved')->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'consumed_by' => $actorId]);
            app(AuditService::class)->record('inventory_document.approved', $document, ['status' => 'pending'], ['status' => 'approved']);
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }

    /**
     * Resolve an unselected batch/lot issue into explicit FEFO allocations.
     * Returning an empty collection preserves legacy unbatched-layer behavior;
     * the central ledger/costing service remains the final stock safeguard.
     */
    private function automaticBatchAllocations(Product $product, float $quantity, ?int $locationId, int $companyId, ?InventoryDocument $document = null): Collection
    {
        if (!in_array($product->tracking_type, ['batch', 'lot'], true) || $quantity <= 0) return collect();

        $allowExpired = (bool) app(ErpSettingService::class)->get('allow_expired_batch_issue', false, $companyId)
            || $this->hasExpiryOverride($document, 'expired');
        $allowPastBestBefore = (bool) app(ErpSettingService::class)->get('allow_past_best_before_issue', false, $companyId)
            || $this->hasExpiryOverride($document, 'best_before');
        $layers = InventoryCostLayer::with('batch')
            ->where('product_id', $product->id)
            ->whereNotNull('batch_id')
            ->where('remaining_quantity', '>', 0)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get()
            ->filter(function (InventoryCostLayer $layer) use ($allowExpired, $allowPastBestBefore): bool {
                $batch = $layer->batch;
                return $batch
                    && ($allowExpired || !$batch->expiry_date || !$batch->expiry_date->lt(Carbon::today()))
                    && ($allowPastBestBefore || !$batch->best_before_date || !$batch->best_before_date->lt(Carbon::today()));
            })
            ->sortBy(fn (InventoryCostLayer $layer): array => [
                ($layer->batch?->expiry_date ?? $layer->batch?->best_before_date)?->timestamp ?? PHP_INT_MAX,
                $layer->received_at?->timestamp ?? 0,
                $layer->id,
            ])->values();

        if ((float) $layers->sum('remaining_quantity') + 0.000001 < $quantity) return collect();
        $remaining = $quantity;
        return $layers->map(function (InventoryCostLayer $layer) use (&$remaining): ?array {
            if ($remaining <= 0.000001) return null;
            $allocated = min($remaining, (float) $layer->remaining_quantity);
            $remaining -= $allocated;
            return ['batch_no' => $layer->batch?->batch_no, 'quantity' => $allocated];
        })->filter()->values();
    }

    private function hasExpiryOverride(?InventoryDocument $document, string $scope): bool
    {
        return $document && InventoryExpiryOverrideRequest::where('company_id', $document->company_id)
            ->where('inventory_document_id', $document->id)->where('status', 'approved')->whereNull('consumed_at')
            ->whereIn('scope', [$scope, 'both'])->exists();
    }

    public function reject(InventoryDocument $source, string $reason, ?User $actor = null): InventoryDocument
    {
        return DB::transaction(function () use ($source, $reason, $actor): InventoryDocument {
            $document = InventoryDocument::where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            if ($document->status !== 'pending') throw new \RuntimeException('This inventory document has already been processed.');
            $before = $document->only(['status', 'inspection_notes']);
            $document->update(['status' => 'rejected', 'inspection_notes' => $reason, 'approved_by' => $actor?->id, 'approved_at' => now()]);
            app(AuditService::class)->record('inventory_document.rejected', $document, $before, $document->only(['status', 'inspection_notes', 'approved_by', 'approved_at']));
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }

    public function inspect(InventoryDocument $source, string $status, string $notes, ?User $actor = null): InventoryDocument
    {
        return DB::transaction(function () use ($source, $status, $notes, $actor): InventoryDocument {
            $document = InventoryDocument::where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            if ($document->document_type !== 'receipt' || $document->status !== 'pending' || $document->inspection_status !== 'pending') throw new \RuntimeException('Only pending stock receipts can be inspected.');
            $before = $document->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
            $document->update(['inspection_status' => $status, 'inspection_notes' => $notes, 'inspected_by' => $actor?->id, 'inspected_at' => now()]);
            app(AuditService::class)->record('inventory_document.inspected', $document, $before, $document->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }
}
