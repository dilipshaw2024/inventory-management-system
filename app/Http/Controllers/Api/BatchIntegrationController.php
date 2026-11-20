<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryCostLayer;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchIntegrationController extends Controller
{
    public function recall(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'recall_reference' => ['required', 'string', 'max:150'],
            'recall_reason' => ['required', 'string', 'max:4000'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required to recall a batch.');
        $batch = InventoryBatch::withoutGlobalScopes()->with('product')->whereKey($id)
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->lockForUpdate()->firstOrFail();
        if ($batch->recalled_at && !$batch->recall_released_at) {
            return response()->json(['data' => $batch, 'status' => 'already_recalled'], 200);
        }
        $before = $batch->only(['recalled_at', 'recalled_by', 'recall_reference', 'recall_reason', 'recall_released_at', 'recall_released_by']);
        $batch->update([
            'recalled_at' => now(), 'recalled_by' => $request->user()->id,
            'recall_reference' => $data['recall_reference'], 'recall_reason' => $data['recall_reason'],
            'recall_released_at' => null, 'recall_released_by' => null,
        ]);
        app(AuditService::class)->record('inventory_batch.recalled', $batch, $before, $batch->only(array_keys($before)));
        return response()->json(['data' => $batch->fresh(['product']), 'status' => 'recalled']);
    }

    public function releaseRecall(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['release_reason' => ['required', 'string', 'max:4000']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required to release a batch recall.');
        $batch = InventoryBatch::withoutGlobalScopes()->with('product')->whereKey($id)
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->lockForUpdate()->firstOrFail();
        if (!$batch->recalled_at || $batch->recall_released_at) {
            return response()->json(['data' => $batch, 'status' => 'not_recalled'], 200);
        }
        $before = $batch->only(['recalled_at', 'recall_released_at', 'recall_released_by', 'recall_reason']);
        $batch->update(['recall_released_at' => now(), 'recall_released_by' => $request->user()->id, 'recall_reason' => trim((string) $batch->recall_reason).'\nRelease: '.$data['release_reason']]);
        app(AuditService::class)->record('inventory_batch.recall_released', $batch, $before, $batch->only(array_keys($before)));
        return response()->json(['data' => $batch->fresh(['product']), 'status' => 'recall_released']);
    }

    public function recalls(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:active,released,all'], 'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'include_zero' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for batch recall reporting.');
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }
        $batches = InventoryBatch::with(['product:id,name,sku,company_id', 'location:id,code,name', 'recalledBy:id,name', 'recallReleasedBy:id,name'])
            ->whereNotNull('recalled_at')
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(($data['status'] ?? 'active') === 'active', fn ($query) => $query->whereNull('recall_released_at'))
            ->when(($data['status'] ?? 'active') === 'released', fn ($query) => $query->whereNotNull('recall_released_at'))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('inventory_batches.updated_at', '>=', $date))
            ->orderByDesc('recalled_at')->orderBy('id')->get();
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $batches = $batches->map(function (InventoryBatch $batch) use ($companyId, $in, $out): InventoryBatch {
            $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
            $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
            $balance = InventoryMovement::query()->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')
                ->where('inventory_movements.product_id', $batch->product_id)
                ->where(fn ($query) => $query->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))
                ->whereRaw($allocationBatch.' = ?', [$batch->id])
                ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN '.$allocationQuantity.' WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -'.$allocationQuantity.' ELSE 0 END), 0) AS balance', array_merge($in, $out))
                ->value('balance');
            $quantity = (float) $balance;
            $batch->setAttribute('stock_quantity', $quantity);
            $costLayers = InventoryCostLayer::withoutGlobalScopes()->where('product_id', $batch->product_id)->where('batch_id', $batch->id)
                ->when($batch->location_id !== null, fn ($query) => $query->where('location_id', $batch->location_id))
                ->where('remaining_quantity', '>', 0)->get(['remaining_quantity', 'unit_cost']);
            $layerValue = (float) $costLayers->sum(fn ($layer): float => (float) $layer->remaining_quantity * (float) $layer->unit_cost);
            $batch->setAttribute('stock_value', $layerValue > 0 ? $layerValue : $quantity * (float) ($batch->product?->purchase_price ?? 0));
            $batch->setAttribute('value_at_risk', (float) $batch->stock_value);
            $batch->setAttribute('recall_status', $batch->recall_released_at ? 'released' : 'active');
            return $batch;
        })->filter(fn (InventoryBatch $batch): bool => ($data['include_zero'] ?? false) || (float) $batch->stock_quantity > 0)->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => [
            'status' => $data['status'] ?? 'active', 'include_zero' => (bool) ($data['include_zero'] ?? false),
            'current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(),
            'last_page' => max(1, (int) ceil($batches->count() / $perPage)),
        ]]);
    }

    public function traceability(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['nullable', 'in:all,inbound,outbound'],
            'location_id' => ['nullable', 'integer'],
            'serial_id' => ['nullable', 'integer'],
            'posted_from' => ['nullable', 'date'],
            'posted_to' => ['nullable', 'date', 'after_or_equal:posted_from'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for batch traceability.');
        $batch = InventoryBatch::whereKey($id)->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'Location is not authorized for this company.');

        $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $movements = InventoryMovement::with([
            'product:id,name,sku,company_id', 'location:id,code,name', 'creator:id,name,email', 'reference',
            'batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'serial:id,product_id,batch_id,serial_no,status,warranty_until',
            'allocations.batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'allocations.serial:id,product_id,batch_id,serial_no,status,warranty_until',
        ])->where('product_id', $batch->product_id)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn ($query) => $query->where('batch_id', $batch->id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('batch_id', $batch->id)))
            ->when($data['location_id'] ?? null, fn ($query, $locationId) => $query->where('location_id', $locationId))
            ->when($data['serial_id'] ?? null, fn ($query, $serialId) => $query->where(fn ($nested) => $nested->where('serial_id', $serialId)->orWhereHas('allocations', fn ($allocation) => $allocation->where('serial_id', $serialId))))
            ->when($data['direction'] ?? null, function ($query, $direction) use ($inbound, $outbound): void {
                if ($direction === 'inbound') $query->whereIn('movement_type', $inbound);
                if ($direction === 'outbound') $query->whereIn('movement_type', $outbound);
            })
            ->when($data['posted_from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($data['posted_to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(\App\Services\IntegrationCursorService::class)->paginate($movements, $request, 'inventory.batch.traceability.'.$id, (int) ($data['per_page'] ?? 50));
    }

    public function stock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'], 'batch_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'manufacturing_date_from' => ['nullable', 'date'], 'manufacturing_date_to' => ['nullable', 'date', 'after_or_equal:manufacturing_date_from'], 'include_zero' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for batch stock reporting.');
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }
        $batches = InventoryBatch::with(['product:id,name,sku,company_id,tracking_type', 'location:id,code,name'])
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['batch_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($data['manufacturing_date_from'] ?? null, fn ($query, $date) => $query->whereDate('manufacturing_date', '>=', $date))
            ->when($data['manufacturing_date_to'] ?? null, fn ($query, $date) => $query->whereDate('manufacturing_date', '<=', $date))
            ->when($data['updated_since'] ?? null, function ($query, $date): void {
                $query->where(function ($changed) use ($date): void {
                    $changed->where('inventory_batches.updated_at', '>=', $date)
                        ->orWhereHas('movements', fn ($movement) => $movement->where('posted_at', '>=', $date))
                        ->orWhereExists(function ($allocation) use ($date): void {
                            $allocation->selectRaw('1')->from('inventory_movement_allocations')
                                ->join('inventory_movements', 'inventory_movements.id', '=', 'inventory_movement_allocations.movement_id')
                                ->whereColumn('inventory_movement_allocations.batch_id', 'inventory_batches.id')
                                ->where('inventory_movements.posted_at', '>=', $date);
                        });
                });
            })
            ->orderBy('updated_at')->orderBy('id')->get();
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $batches = $batches->map(function (InventoryBatch $batch) use ($companyId, $data, $in, $out): InventoryBatch {
            $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
            $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
            $balance = InventoryMovement::query()->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')
                ->where('inventory_movements.product_id', $batch->product_id)
                ->where(fn ($query) => $query->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))
                ->whereRaw($allocationBatch.' = ?', [$batch->id])
                ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('inventory_movements.location_id', $id))
                ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN '.$allocationQuantity.' WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -'.$allocationQuantity.' ELSE 0 END), 0) AS balance', array_merge($in, $out))
                ->value('balance');
            $batch->setAttribute('stock_quantity', (float) $balance);
            $batch->setAttribute('stock_value', (float) $balance * (float) ($batch->product?->purchase_price ?? 0));
            return $batch;
        })->filter(fn (InventoryBatch $batch): bool => ($data['include_zero'] ?? false) || (float) $batch->stock_quantity > 0)->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(), 'last_page' => max(1, (int) ceil($batches->count() / $perPage)), 'include_zero' => (bool) ($data['include_zero'] ?? false), 'manufacturing_date_from' => $data['manufacturing_date_from'] ?? null, 'manufacturing_date_to' => $data['manufacturing_date_to'] ?? null, 'updated_since' => $data['updated_since'] ?? null]]);
    }

    public function expiry(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'manufacturing_date_from' => ['nullable', 'date'], 'manufacturing_date_to' => ['nullable', 'date', 'after_or_equal:manufacturing_date_from'], 'include_expired' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for batch reporting.');
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'Location is not authorized for this company.');
        $days = (int) ($data['days'] ?? 90); $cutoff = now()->addDays($days)->toDateString();
        $batches = InventoryBatch::with(['product:id,name,sku,company_id', 'location:id,code,name'])
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn ($query) => $query->whereNotNull('expiry_date')->orWhereNotNull('best_before_date'))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['manufacturing_date_from'] ?? null, fn ($query, $date) => $query->whereDate('manufacturing_date', '>=', $date))
            ->when($data['manufacturing_date_to'] ?? null, fn ($query, $date) => $query->whereDate('manufacturing_date', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->when(empty($data['include_expired']), fn ($query) => $query->whereRaw('(COALESCE(expiry_date, best_before_date) <= ?)', [$cutoff]))
            ->orderByRaw('COALESCE(expiry_date, best_before_date)')->orderBy('id')->get();
        $batches = $batches->map(function (InventoryBatch $batch) use ($companyId): InventoryBatch {
            $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release']; $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
            $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
            $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
            $balance = InventoryMovement::query()->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')->where('inventory_movements.product_id', $batch->product_id)->where(fn ($query) => $query->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))->whereRaw($allocationBatch.' = ?', [$batch->id])->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN '.$allocationQuantity.' WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -'.$allocationQuantity.' ELSE 0 END), 0) AS balance', array_merge($in, $out))->value('balance');
            $stockQuantity = (float) $balance; $riskDate = $batch->expiry_date ?? $batch->best_before_date; $batch->setAttribute('stock_quantity', $stockQuantity); $batch->setAttribute('value_at_risk', $stockQuantity * (float) ($batch->product?->purchase_price ?? 0)); $batch->setAttribute('is_expired', $riskDate?->isPast() ?? false); return $batch;
        })->filter(fn (InventoryBatch $batch): bool => (float) $batch->stock_quantity > 0)->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => ['days' => $days, 'include_expired' => (bool) ($data['include_expired'] ?? false), 'manufacturing_date_from' => $data['manufacturing_date_from'] ?? null, 'manufacturing_date_to' => $data['manufacturing_date_to'] ?? null, 'updated_since' => $data['updated_since'] ?? null, 'current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(), 'last_page' => max(1, (int) ceil($batches->count() / $perPage))]]);
    }

    public function warranty(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'include_expired' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for batch warranty reporting.');
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'Location is not authorized for this company.');
        $days = (int) ($data['days'] ?? 365);
        $cutoff = now()->addDays($days)->toDateString();
        $batches = InventoryBatch::with(['product:id,name,sku,company_id', 'location:id,code,name'])
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereNotNull('warranty_until')
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->when(empty($data['include_expired']), fn ($query) => $query->whereDate('warranty_until', '>=', now()->toDateString())->whereDate('warranty_until', '<=', $cutoff))
            ->orderBy('warranty_until')->orderBy('id')->get();
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $batches = $batches->map(function (InventoryBatch $batch) use ($companyId, $in, $out): InventoryBatch {
            $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
            $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
            $balance = InventoryMovement::query()->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')
                ->where('inventory_movements.product_id', $batch->product_id)
                ->where(fn ($query) => $query->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))
                ->whereRaw($allocationBatch.' = ?', [$batch->id])
                ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN '.$allocationQuantity.' WHEN inventory_movements.movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -'.$allocationQuantity.' ELSE 0 END), 0) AS balance', array_merge($in, $out))->value('balance');
            $stockQuantity = (float) $balance;
            $batch->setAttribute('stock_quantity', $stockQuantity);
            $batch->setAttribute('warranty_status', $batch->warranty_until?->isPast() ? 'expired' : 'active');
            $batch->setAttribute('days_until_warranty', now()->startOfDay()->diffInDays($batch->warranty_until?->startOfDay(), false));
            return $batch;
        })->filter(fn (InventoryBatch $batch): bool => (float) $batch->stock_quantity > 0)->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => ['days' => $days, 'include_expired' => (bool) ($data['include_expired'] ?? false), 'current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(), 'last_page' => max(1, (int) ceil($batches->count() / $perPage))]]);
    }
}
