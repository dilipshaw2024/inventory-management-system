<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\InventoryLocation;
use App\Models\Department;
use App\Models\CostCenter;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class InventoryLedgerService
{
    public function post(
        int $productId,
        string $movementType,
        float $quantity,
        ?float $unitCost = null,
        ?int $locationId = null,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $batchId = null,
        ?int $serialId = null,
        ?int $departmentId = null,
        ?int $costCenterId = null
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inventory movement quantity must be greater than zero.');
        }

        $companyId = $reference?->getAttribute('company_id') ?: auth()->user()?->company_id;
        $productQuery = Product::withoutGlobalScope('company')->whereKey($productId);
        if ($companyId !== null) $productQuery->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $product = $productQuery->lockForUpdate()->firstOrFail();
        if ($product->is_stock_item === false || $product->is_stock_item === 0 || $product->is_stock_item === '0') {
            throw new \RuntimeException('Non-stock product '.$product->name.' cannot create an inventory movement.');
        }

        $postingDate = $reference?->getAttribute('date') ?: $reference?->getAttribute('invoice_date') ?: $reference?->getAttribute('receipt_date') ?: $reference?->getAttribute('delivery_date') ?: $reference?->getAttribute('order_date') ?: now()->toDateString();
        app(FiscalPeriodService::class)->assertOpen($companyId, Carbon::parse($postingDate)->toDateString());

        $batchRecord = null;
        if ($batchId !== null) {
            $batchRecord = InventoryBatch::withoutGlobalScopes()->whereKey($batchId)->where('product_id', $productId)->lockForUpdate()->first();
            if (!$batchRecord) throw new \RuntimeException('The selected batch does not belong to the posted product.');
        }
        if ($serialId !== null) {
            $serialRecord = InventorySerial::withoutGlobalScopes()->whereKey($serialId)->where('product_id', $productId)->lockForUpdate()->first();
            if (!$serialRecord) throw new \RuntimeException('The selected serial number does not belong to the posted product.');
            if ($batchId !== null && (int) $serialRecord->batch_id !== (int) $batchId) throw new \RuntimeException('The selected serial number does not belong to the selected batch.');
        }

        $location = null;
        if ($locationId !== null) {
            $locationQuery = InventoryLocation::withoutGlobalScopes()->whereKey($locationId);
            if ($companyId !== null) $locationQuery->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
            $location = $locationQuery->lockForUpdate()->first();
            if (!$location) throw new \RuntimeException('The selected inventory location is not authorized for this company.');
        }
        if ($departmentId !== null) {
            $department = Department::withoutGlobalScopes()->whereKey($departmentId)->where('is_active', true)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->first();
            if (!$department) throw new \RuntimeException('The selected department is not authorized for this company.');
        }
        if ($costCenterId !== null) {
            $costCenter = CostCenter::withoutGlobalScopes()->whereKey($costCenterId)->where('is_active', true)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->first();
            if (!$costCenter) throw new \RuntimeException('The selected cost center is not authorized for this company.');
        }
        if ($location !== null && in_array($movementType, ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in'], true)) {
            app(InventoryLocationCapacityService::class)->assertCanReceive($location, $product, $quantity);
        }

        if (!$reference instanceof StockCount && StockCount::withoutGlobalScopes()->where('status', 'submitted')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->whereHas('lines', fn ($query) => $query->where('product_id', $productId))->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $locationId))->exists()) {
            throw new \RuntimeException('Inventory is frozen for this product/location while a stock count is awaiting approval.');
        }

        $outboundMovement = in_array($movementType, ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap'], true);
        $negativeStockPolicy = app(ErpSettingService::class)->get('negative_stock_policy', 'block', $companyId);
        if ($negativeStockPolicy === 'block' && $this->storeAllowsNegativeStock($reference, $companyId)) {
            $negativeStockPolicy = 'allow';
        }
        $approvedOverride = $negativeStockPolicy === 'approval' && $this->hasIndependentApproval($reference);
        if ($outboundMovement && $negativeStockPolicy !== 'allow' && !$approvedOverride) {
            $available = app(InventoryAvailabilityService::class)->available($product, false, $locationId, $companyId);
            if ($available < $quantity) {
                throw new \RuntimeException('Insufficient available stock for '.$product->name.'. Available: '.$available.', requested: '.$quantity.'.');
            }
        }

        if (in_array($movementType, ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap'], true)) {
            $batch = $batchRecord
                ? $batchRecord
                : ($serialId ? InventorySerial::where('product_id', $productId)->with('batch')->findOrFail($serialId)->batch : null);
            if ($batch && $batch->expiry_date && $batch->expiry_date->lt(Carbon::today()) && !app(ErpSettingService::class)->get('allow_expired_batch_issue', false, $companyId)) {
                throw new \RuntimeException('Expired batch '.$batch->batch_no.' cannot be issued without the company expiry-policy override.');
            }
            if ($batch && $batch->best_before_date && $batch->best_before_date->lt(Carbon::today()) && !app(ErpSettingService::class)->get('allow_past_best_before_issue', false, $companyId)) {
                throw new \RuntimeException('Batch '.$batch->batch_no.' is past its best-before date and cannot be issued without the company best-before override.');
            }
        }

        $movement = InventoryMovement::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'department_id' => $departmentId,
            'cost_center_id' => $costCenterId,
            'batch_id' => $batchId,
            'serial_id' => $serialId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'reference_no' => $reference?->getAttribute('purchase_no') ?? $reference?->getAttribute('invoice_no'),
            'reason' => $reason,
            'created_by' => $userId ?? auth()->id(),
            'posted_at' => now(),
        ]);

        $costing = app(InventoryCostingService::class);
        if (in_array($movementType, ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in'], true)) {
            $costing->receipt($productId, $quantity, $unitCost ?? 0, $locationId, $batchId, $movement, $serialId);
        } elseif (in_array($movementType, ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap'], true)) {
            $totalCost = $costing->consume($productId, $quantity, $locationId, $movement, $unitCost, $batchId, $serialId);
            $movement->finalizeUnitCost($quantity > 0 ? $totalCost / $quantity : 0);
            app(AutomaticAccountingService::class)->postInventoryMovement($movement, $totalCost, $reference);
            return $movement;
        }

        if (in_array($movementType, ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in'], true)) {
            app(AutomaticAccountingService::class)->postInventoryMovement($movement, $quantity * (float) ($unitCost ?? 0), $reference);
        }

        return $movement;
    }

    private function hasIndependentApproval(?Model $reference): bool
    {
        if (!$reference) return false;
        $status = $reference->getAttribute('status');
        $approved = $status === 'approved' || $status === 1 || $status === '1';
        $approverId = $reference->getAttribute('approved_by');
        $creatorId = $reference->getAttribute('created_by');
        return $approved && $approverId !== null && (!$creatorId || (int) $creatorId !== (int) $approverId);
    }

    private function storeAllowsNegativeStock(?Model $reference, ?int $companyId): bool
    {
        $storeId = $reference?->getAttribute('store_id');
        if (!$storeId) return false;
        return (bool) Store::withoutGlobalScopes()
            ->whereKey($storeId)
            ->where('allow_negative_stock', true)
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->value('allow_negative_stock');
    }
}
