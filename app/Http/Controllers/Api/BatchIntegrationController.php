<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchIntegrationController extends Controller
{
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
            'product:id,name,sku,company_id', 'location:id,code,name', 'creator:id,name,email',
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
            'location_id' => ['nullable', 'integer'], 'include_zero' => ['nullable', 'boolean'],
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
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(), 'last_page' => max(1, (int) ceil($batches->count() / $perPage)), 'include_zero' => (bool) ($data['include_zero'] ?? false), 'updated_since' => $data['updated_since'] ?? null]]);
    }

    public function expiry(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'include_expired' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for batch reporting.');
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'Location is not authorized for this company.');
        $days = (int) ($data['days'] ?? 90); $cutoff = now()->addDays($days)->toDateString();
        $batches = InventoryBatch::with(['product:id,name,sku,company_id', 'location:id,code,name'])
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn ($query) => $query->whereNotNull('expiry_date')->orWhereNotNull('best_before_date'))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
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
        return response()->json(['data' => $batches->forPage($page, $perPage)->values(), 'meta' => ['days' => $days, 'include_expired' => (bool) ($data['include_expired'] ?? false), 'updated_since' => $data['updated_since'] ?? null, 'current_page' => $page, 'per_page' => $perPage, 'total' => $batches->count(), 'last_page' => max(1, (int) ceil($batches->count() / $perPage))]]);
    }
}
