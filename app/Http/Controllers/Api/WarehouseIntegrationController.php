<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryTransferSerial;
use App\Models\InventoryTransferAllocation;
use App\Models\Delivery;
use App\Models\PickWave;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\AutomaticAccountingService;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryLedgerService;
use App\Services\InventoryLocationCapacityService;
use App\Services\NumberingSequenceService;
use App\Services\SerialLifecycleService;
use App\Services\WarehouseFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class WarehouseIntegrationController extends Controller
{
    public function pickList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'in:location,product,delivery_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for warehouse picking.');

        $deliveries = Delivery::with(['lines.product', 'salesOrder.customer', 'location.warehouse', 'operations'])
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', 'pending'))
            ->whereDoesntHave('operations', fn ($query) => $query->where('operation_type', 'pick')->where('status', 'completed'))
            ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->whereHas('location', fn ($location) => $location->where('warehouse_id', $id)))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['date'] ?? null, fn ($query, $date) => $query->whereDate('date', $date))
            ->orderBy('date')->orderBy('id')->get();

        $sortBy = $data['sort_by'] ?? 'location';
        $rows = $deliveries->map(function (Delivery $delivery): array {
            $lines = $delivery->lines->map(fn ($line): array => [
                'id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'quantity' => (float) $line->delivered_qty,
                'uom_id' => $line->uom_id,
            ])->values();
            return [
                'delivery_id' => (int) $delivery->id,
                'delivery_no' => $delivery->delivery_no,
                'date' => optional($delivery->date)->toDateString(),
                'location_id' => $delivery->location_id ? (int) $delivery->location_id : null,
                'location' => $delivery->location?->code,
                'warehouse_id' => $delivery->location?->warehouse_id ? (int) $delivery->location->warehouse_id : null,
                'warehouse' => $delivery->location?->warehouse?->name,
                'customer_id' => $delivery->salesOrder?->customer_id ? (int) $delivery->salesOrder->customer_id : null,
                'customer' => $delivery->salesOrder?->customer?->name,
                'lines' => $lines,
                'line_count' => $lines->count(),
                'total_quantity' => (float) $lines->sum('quantity'),
            ];
        })->filter(fn (array $row): bool => $row['line_count'] > 0);

        $rows = $rows->sortBy(function (array $row) use ($sortBy): array {
            $firstProduct = (string) ($row['lines']->first()['product'] ?? '');
            return match ($sortBy) {
                'product' => [$firstProduct, (string) ($row['location'] ?? ''), $row['date'] ?? '', $row['delivery_id']],
                'delivery_date' => [$row['date'] ?? '', (string) ($row['location'] ?? ''), $row['delivery_id']],
                default => [(string) ($row['location'] ?? 'ZZZ'), $firstProduct, $row['date'] ?? '', $row['delivery_id']],
            };
        })->values()->map(function (array $row, int $index): array {
            $row['pick_sequence'] = $index + 1;
            return $row;
        });

        $perPage = (int) ($data['per_page'] ?? 50);
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        return response()->json([
            'data' => $paginator->getCollection(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
                'sort_by' => $sortBy, 'warehouse_id' => $data['warehouse_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'total_lines' => $rows->sum('line_count'), 'total_quantity' => (float) $rows->sum('total_quantity'),
            ],
        ]);
    }

    public function completePickList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wave_id' => ['nullable', 'integer'],
            'deliveries' => ['required', 'array', 'min:1', 'max:100'],
            'deliveries.*.delivery_id' => ['required', 'integer', 'distinct'],
            'deliveries.*.confirmed_quantities' => ['nullable', 'array'],
            'deliveries.*.confirmed_quantities.*' => ['numeric', 'min:0'],
            'deliveries.*.scans' => ['nullable', 'array', 'min:1', 'max:500'],
            'deliveries.*.scans.*.code' => ['required', 'string', 'max:150'],
            'deliveries.*.scans.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for warehouse picking.');

        try {
            $operations = DB::transaction(function () use ($data, $companyId, $request): array {
                $deliveryIds = collect($data['deliveries'])->pluck('delivery_id')->map(fn ($id): int => (int) $id)->values();
                $wave = null;
                if (!empty($data['wave_id'])) {
                    $wave = PickWave::where('company_id', $companyId)->lockForUpdate()->findOrFail((int) $data['wave_id']);
                    if (!in_array($wave->status, ['released', 'in_progress'], true)) {
                        throw new \RuntimeException('Only released or in-progress pick waves can receive pick confirmations.');
                    }
                    $waveDeliveryIds = $wave->deliveries()->pluck('deliveries.id')->map(fn ($id): int => (int) $id);
                    if ($deliveryIds->diff($waveDeliveryIds)->isNotEmpty()) {
                        throw new \RuntimeException('Every confirmed delivery must belong to the selected pick wave.');
                    }
                }
                $deliveries = Delivery::with(['lines.product.barcodes', 'operations'])
                    ->where('company_id', $companyId)
                    ->whereIn('id', $deliveryIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($deliveries->count() !== $deliveryIds->count()) {
                    throw new \RuntimeException('One or more deliveries are not available in the current company.');
                }

                $completed = [];
                foreach ($data['deliveries'] as $item) {
                    $delivery = $deliveries->get((int) $item['delivery_id']);
                    if (!empty($item['scans']) && array_key_exists('confirmed_quantities', $item)) {
                        throw new \RuntimeException('Provide confirmed quantities or scanner codes for a delivery, not both.');
                    }
                    $confirmedQuantities = !empty($item['scans'])
                        ? $this->resolveScanQuantities($delivery, $item['scans'], (int) $companyId)
                        : ($item['confirmed_quantities'] ?? null);
                    $operation = app(WarehouseFulfillmentService::class)->complete(
                        $delivery,
                        'pick',
                        $confirmedQuantities
                    );
                    app(AuditService::class)->record('delivery.pick.completed', $delivery, null, [
                        'operation_id' => $operation->id,
                        'confirmed_quantities' => $operation->confirmed_quantities,
                        'scans' => $item['scans'] ?? null,
                        'batch' => true,
                        'performed_by' => $request->user()?->id,
                    ]);
                    $completed[] = $operation->fresh()->load('delivery');
                }

                if ($wave && $wave->status === 'released') {
                    $wave->update(['status' => 'in_progress']);
                    app(AuditService::class)->record('pick_wave.started', $wave, ['status' => 'released'], ['status' => 'in_progress']);
                }

                return $completed;
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $operations,
            'meta' => ['delivery_count' => count($operations), 'operation_type' => 'pick', 'wave_id' => $data['wave_id'] ?? null],
            'status' => 'completed',
        ]);
    }

    public function pickWaves(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:planned,released,in_progress,completed,cancelled'],
            'date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for warehouse wave picking.');

        $waves = PickWave::with(['warehouse', 'deliveries.location', 'deliveries.operations'])
            ->where('company_id', $companyId)
            ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['date'] ?? null, fn ($query, $date) => $query->whereDate('wave_date', $date))
            ->orderByDesc('wave_date')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => $waves->getCollection()->map(fn (PickWave $wave): array => $this->wavePayload($wave))->values(),
            'meta' => ['current_page' => $waves->currentPage(), 'per_page' => $waves->perPage(), 'total' => $waves->total(), 'last_page' => $waves->lastPage()],
        ]);
    }

    public function createPickWave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'delivery_ids' => ['required', 'array', 'min:1', 'max:100'],
            'delivery_ids.*' => ['required', 'integer', 'distinct'],
            'wave_date' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for warehouse wave picking.');

        if (!empty($data['external_reference'])) {
            $existing = PickWave::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $this->wavePayload($existing->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'duplicate_ignored']);
        }

        try {
            $wave = DB::transaction(function () use ($data, $companyId, $request): PickWave {
                $warehouse = Warehouse::whereKey($data['warehouse_id'])
                    ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
                    ->firstOrFail();
                $deliveryIds = collect($data['delivery_ids'])->map(fn ($id): int => (int) $id)->values();
                $deliveries = Delivery::with(['operations', 'location'])
                    ->where('company_id', $companyId)
                    ->whereIn('id', $deliveryIds)
                    ->lockForUpdate()->get()->keyBy('id');
                if ($deliveries->count() !== $deliveryIds->count()) throw new \RuntimeException('One or more deliveries are not available in the current company.');

                foreach ($deliveries as $delivery) {
                    if ($delivery->status !== 'pending' || !in_array($delivery->fulfillment_status ?: 'pending', ['pending'], true)) throw new \RuntimeException('Only pending deliveries can be assigned to a pick wave.');
                    if ((int) $delivery->location?->warehouse_id !== (int) $warehouse->id) throw new \RuntimeException('Every delivery must belong to the selected warehouse.');
                    if ($delivery->operations->firstWhere('operation_type', 'pick')?->status === 'completed') throw new \RuntimeException('A delivery with completed picking cannot be assigned to a new wave.');
                    if ($delivery->pickWaves()->whereIn('pick_waves.status', ['planned', 'released', 'in_progress'])->exists()) throw new \RuntimeException('A delivery is already assigned to an active pick wave.');
                }

                $fallback = 'PW-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
                $wave = PickWave::create([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouse->id,
                    'wave_no' => app(\App\Services\NumberingSequenceService::class)->nextOrFallback('pick_wave', $fallback, $companyId, $warehouse->branch_id),
                    'external_reference' => $data['external_reference'] ?? null,
                    'wave_date' => $data['wave_date'] ?? now()->toDateString(),
                    'status' => 'planned',
                    'created_by' => $request->user()?->id,
                ]);
                $wave->deliveries()->attach($deliveryIds->all());
                app(AuditService::class)->record('pick_wave.created', $wave, null, ['delivery_ids' => $deliveryIds->all(), 'warehouse_id' => $warehouse->id]);
                return $wave;
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->wavePayload($wave->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'created'], 201);
    }

    public function releasePickWave(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $wave = PickWave::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
        if ($wave->status === 'released') return response()->json(['data' => $this->wavePayload($wave->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'already_released']);
        if ($wave->status !== 'planned') return response()->json(['message' => 'Only planned pick waves can be released.'], 422);
        $before = $wave->only(['status', 'released_at', 'released_by']);
        $wave->update(['status' => 'released', 'released_by' => $request->user()?->id, 'released_at' => now()]);
        app(AuditService::class)->record('pick_wave.released', $wave, $before, $wave->fresh()->only(['status', 'released_at', 'released_by']));
        return response()->json(['data' => $this->wavePayload($wave->fresh()->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'released']);
    }

    public function completePickWave(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $wave = PickWave::where('company_id', $companyId)->with(['deliveries.operations'])->lockForUpdate()->findOrFail($id);
        if ($wave->status === 'completed') return response()->json(['data' => $this->wavePayload($wave->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'already_completed']);
        if (!in_array($wave->status, ['released', 'in_progress'], true)) return response()->json(['message' => 'Only released or in-progress pick waves can be completed.'], 422);
        if ($wave->deliveries->contains(fn (Delivery $delivery): bool => $delivery->operations->firstWhere('operation_type', 'pick')?->status !== 'completed')) return response()->json(['message' => 'Every delivery in the wave must complete picking first.'], 422);
        $before = $wave->only(['status', 'completed_at']);
        $wave->update(['status' => 'completed', 'completed_at' => now()]);
        app(AuditService::class)->record('pick_wave.completed', $wave, $before, $wave->fresh()->only(['status', 'completed_at']));
        return response()->json(['data' => $this->wavePayload($wave->fresh()->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'completed']);
    }

    public function cancelPickWave(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $companyId = $request->user()?->company_id;
        $wave = PickWave::where('company_id', $companyId)->with(['deliveries.operations'])->lockForUpdate()->findOrFail($id);
        if ($wave->status === 'cancelled') return response()->json(['data' => $this->wavePayload($wave->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'already_cancelled']);
        if ($wave->status === 'completed') return response()->json(['message' => 'Completed pick waves cannot be cancelled.'], 422);
        if (!in_array($wave->status, ['planned', 'released', 'in_progress'], true)) return response()->json(['message' => 'This pick wave cannot be cancelled in its current state.'], 422);
        if ($wave->deliveries->contains(fn (Delivery $delivery): bool => $delivery->operations->firstWhere('operation_type', 'pick')?->status === 'completed')) return response()->json(['message' => 'A pick wave cannot be cancelled after picking has started.'], 422);
        $before = $wave->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
        $wave->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_by' => $request->user()?->id, 'cancelled_at' => now()]);
        app(AuditService::class)->record('pick_wave.cancelled', $wave, $before, $wave->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']));
        return response()->json(['data' => $this->wavePayload($wave->fresh()->load(['warehouse', 'deliveries.location', 'deliveries.operations'])), 'status' => 'cancelled']);
    }

    private function wavePayload(PickWave $wave): array
    {
        $deliveries = $wave->deliveries ?? collect();
        return [
            'id' => (int) $wave->id, 'wave_no' => $wave->wave_no, 'external_reference' => $wave->external_reference,
            'warehouse_id' => (int) $wave->warehouse_id, 'warehouse' => $wave->warehouse?->name,
            'wave_date' => optional($wave->wave_date)->toDateString(), 'status' => $wave->status,
            'released_at' => optional($wave->released_at)->toISOString(), 'completed_at' => optional($wave->completed_at)->toISOString(),
            'cancelled_at' => optional($wave->cancelled_at)->toISOString(), 'cancellation_reason' => $wave->cancellation_reason,
            'delivery_count' => $deliveries->count(), 'delivery_ids' => $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            'completed_pick_count' => $deliveries->filter(fn (Delivery $delivery): bool => $delivery->operations?->firstWhere('operation_type', 'pick')?->status === 'completed')->count(),
        ];
    }

    private function resolveScanQuantities(Delivery $delivery, array $scans, int $companyId): array
    {
        $quantities = [];
        foreach ($scans as $scan) {
            $code = trim((string) $scan['code']);
            $matches = $delivery->lines->filter(function ($line) use ($code): bool {
                $product = $line->product;
                return $product && ($product->sku === $code || $product->barcode === $code || $product->barcodes->contains('code', $code));
            });
            if ($matches->count() !== 1) throw new \RuntimeException('Scanner code '.$code.' is unknown or ambiguous for this company.');
            $lineId = (string) $matches->first()->id;
            $quantities[$lineId] = ($quantities[$lineId] ?? 0) + (float) $scan['quantity'];
        }
        return $quantities;
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for warehouse reporting.');
        $warehouses = Warehouse::with('branch')->whereHas('branch', fn ($q) => $q->where('company_id', $companyId))->when($data['warehouse_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release']; $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in']; $inPlaceholders = implode(',', array_fill(0, count($in), '?')); $outPlaceholders = implode(',', array_fill(0, count($out), '?'));
        $occupied = InventoryMovement::query()->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_movements.location_id')->whereIn('inventory_locations.warehouse_id', $warehouses->pluck('id'))->where(fn ($q) => $q->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))->selectRaw("inventory_locations.warehouse_id, COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($inPlaceholders) THEN inventory_movements.quantity WHEN inventory_movements.movement_type IN ($outPlaceholders) THEN -inventory_movements.quantity ELSE 0 END), 0) AS occupied", array_merge($in, $out))->groupBy('inventory_locations.warehouse_id')->pluck('occupied', 'warehouse_id');
        $period = collect();
        if (!empty($data['from']) && !empty($data['to'])) {
            $period = InventoryMovement::query()->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_movements.location_id')->whereIn('inventory_locations.warehouse_id', $warehouses->pluck('id'))->whereBetween('inventory_movements.posted_at', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])->where(fn ($q) => $q->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))->selectRaw("inventory_locations.warehouse_id, COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($inPlaceholders) THEN inventory_movements.quantity ELSE 0 END), 0) AS inbound, COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($outPlaceholders) THEN inventory_movements.quantity ELSE 0 END), 0) AS outbound", array_merge($in, $out))->groupBy('inventory_locations.warehouse_id')->get()->keyBy('warehouse_id');
        }
        $capacity = InventoryLocation::whereIn('warehouse_id', $warehouses->pluck('id'))->whereNotNull('capacity')->selectRaw('warehouse_id, COALESCE(SUM(capacity), 0) AS capacity')->groupBy('warehouse_id')->pluck('capacity', 'warehouse_id');
        $locations = InventoryLocation::with(['movements.product:id,weight_kg,length_m,width_m,height_m'])->whereIn('warehouse_id', $warehouses->pluck('id'))->get();
        $physicalCapacity = $locations->groupBy('warehouse_id')->map(fn ($warehouseLocations): array => [
            'weight_kg' => (float) $warehouseLocations->sum(fn (InventoryLocation $location): float => (float) ($location->capacity_weight_kg ?? 0)),
            'volume_m3' => (float) $warehouseLocations->sum(fn (InventoryLocation $location): float => (float) ($location->capacity_volume_m3 ?? 0)),
        ]);
        $physicalOccupied = $locations->groupBy('warehouse_id')->map(fn ($warehouseLocations): array => [
            'weight_kg' => (float) $warehouseLocations->sum(fn (InventoryLocation $location): float => app(InventoryLocationCapacityService::class)->occupied($location)['weight_kg']),
            'volume_m3' => (float) $warehouseLocations->sum(fn (InventoryLocation $location): float => app(InventoryLocationCapacityService::class)->occupied($location)['volume_m3']),
        ]);
        $rows = $warehouses->map(function (Warehouse $warehouse) use ($occupied, $capacity, $physicalCapacity, $physicalOccupied, $period): array { $used = max(0, (float) ($occupied[$warehouse->id] ?? 0)); $totalCapacity = (float) ($capacity[$warehouse->id] ?? 0); $physicalLimits = $physicalCapacity->get($warehouse->id, ['weight_kg' => 0, 'volume_m3' => 0]); $physicalUsed = $physicalOccupied->get($warehouse->id, ['weight_kg' => 0, 'volume_m3' => 0]); $periodRow = $period->get($warehouse->id); $inbound = $periodRow ? (float) $periodRow->inbound : null; $outbound = $periodRow ? (float) $periodRow->outbound : null; return ['warehouse_id' => $warehouse->id, 'warehouse' => $warehouse, 'occupied_quantity' => $used, 'capacity' => $totalCapacity > 0 ? $totalCapacity : null, 'available_capacity' => $totalCapacity > 0 ? max(0, $totalCapacity - $used) : null, 'utilization_percent' => $totalCapacity > 0 ? min(100, ($used / $totalCapacity) * 100) : null, 'occupied_weight_kg' => $physicalUsed['weight_kg'] > 0 ? $physicalUsed['weight_kg'] : null, 'capacity_weight_kg' => $physicalLimits['weight_kg'] > 0 ? $physicalLimits['weight_kg'] : null, 'available_weight_kg' => $physicalLimits['weight_kg'] > 0 ? max(0, $physicalLimits['weight_kg'] - $physicalUsed['weight_kg']) : null, 'occupied_volume_m3' => $physicalUsed['volume_m3'] > 0 ? $physicalUsed['volume_m3'] : null, 'capacity_volume_m3' => $physicalLimits['volume_m3'] > 0 ? $physicalLimits['volume_m3'] : null, 'available_volume_m3' => $physicalLimits['volume_m3'] > 0 ? max(0, $physicalLimits['volume_m3'] - $physicalUsed['volume_m3']) : null, 'period_inbound' => $inbound, 'period_outbound' => $outbound, 'period_net' => $inbound === null ? null : $inbound - $outbound]; });
        $page = max(1, (int) $request->input('page', 1)); $perPage = (int) ($data['per_page'] ?? 50); return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]]);
    }

    public function locationUtilization(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:warehouse,zone,rack,shelf,bin'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for location utilization reporting.');
        $alertThreshold = (float) app(\App\Services\ErpSettingService::class)->get('warehouse_capacity_alert_percent', 80, (int) $companyId);
        $alertThreshold = $alertThreshold > 0 && $alertThreshold <= 100 ? $alertThreshold : 80;
        $locations = InventoryLocation::with(['warehouse.branch', 'parent'])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderBy('warehouse_id')->orderBy('id')->get()
            ->map(function (InventoryLocation $location) use ($alertThreshold): array {
                $occupied = app(InventoryLocationCapacityService::class)->occupied($location);
                $quantityCapacity = $location->capacity === null ? null : (float) $location->capacity;
                $weightCapacity = $location->capacity_weight_kg === null ? null : (float) $location->capacity_weight_kg;
                $volumeCapacity = $location->capacity_volume_m3 === null ? null : (float) $location->capacity_volume_m3;
                $utilizations = array_filter([
                    $quantityCapacity && $quantityCapacity > 0 ? ($occupied['quantity'] / $quantityCapacity) * 100 : null,
                    $weightCapacity && $weightCapacity > 0 ? ($occupied['weight_kg'] / $weightCapacity) * 100 : null,
                    $volumeCapacity && $volumeCapacity > 0 ? ($occupied['volume_m3'] / $volumeCapacity) * 100 : null,
                ], static fn ($value): bool => $value !== null);
                $maximumUtilization = $utilizations ? max($utilizations) : null;
                return [
                    'location_id' => (int) $location->id,
                    'warehouse_id' => (int) $location->warehouse_id,
                    'code' => $location->code,
                    'name' => $location->name,
                    'type' => $location->type,
                    'parent_id' => $location->parent_id,
                    'is_active' => (bool) $location->is_active,
                    'occupied_quantity' => round($occupied['quantity'], 6),
                    'capacity' => $quantityCapacity,
                    'available_capacity' => $quantityCapacity === null ? null : round(max(0, $quantityCapacity - $occupied['quantity']), 6),
                    'utilization_percent' => $quantityCapacity && $quantityCapacity > 0 ? round(min(100, ($occupied['quantity'] / $quantityCapacity) * 100), 4) : null,
                    'capacity_status' => $maximumUtilization === null ? 'not_configured' : ($maximumUtilization >= 100 ? 'full' : ($maximumUtilization >= $alertThreshold ? 'warning' : 'normal')),
                    'capacity_alert_threshold_percent' => $maximumUtilization === null ? null : $alertThreshold,
                    'occupied_weight_kg' => round($occupied['weight_kg'], 6),
                    'capacity_weight_kg' => $weightCapacity,
                    'weight_utilization_percent' => $weightCapacity && $weightCapacity > 0 ? round(min(100, ($occupied['weight_kg'] / $weightCapacity) * 100), 4) : null,
                    'occupied_volume_m3' => round($occupied['volume_m3'], 6),
                    'capacity_volume_m3' => $volumeCapacity,
                    'volume_utilization_percent' => $volumeCapacity && $volumeCapacity > 0 ? round(min(100, ($occupied['volume_m3'] / $volumeCapacity) * 100), 4) : null,
                ];
            })->values();
        $perPage = (int) ($data['per_page'] ?? 50);
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator($locations->forPage($page, $perPage)->values(), $locations->count(), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        return response()->json(['data' => $paginator->getCollection(), 'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'warehouse_id' => $data['warehouse_id'] ?? null, 'type' => $data['type'] ?? null]]);
    }

    public function putawayLocations(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'warehouse_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $quantity = (float) ($data['quantity'] ?? 0);
        $product = !empty($data['product_id']) ? Product::whereKey($data['product_id'])->firstOrFail() : null;
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $locations = InventoryLocation::with('warehouse')
            ->where('is_active', true)
            ->whereIn('type', ['bin', 'shelf', 'rack'])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->get()
            ->map(function (InventoryLocation $location) use ($in, $out, $quantity, $product): InventoryLocation {
                $placeholders = implode(',', array_fill(0, count($in), '?'));
                $outPlaceholders = implode(',', array_fill(0, count($out), '?'));
                $occupied = (float) $location->movements()->selectRaw(
                    "COALESCE(SUM(CASE WHEN movement_type IN ($placeholders) THEN quantity WHEN movement_type IN ($outPlaceholders) THEN -quantity ELSE 0 END), 0) AS balance",
                    array_merge($in, $out)
                )->value('balance');
                $available = $location->capacity === null ? null : max(0, (float) $location->capacity - $occupied);
                $location->setAttribute('occupied_quantity', max(0, $occupied));
                $location->setAttribute('available_capacity', $available);
                $physicalCapacityOk = true;
                if ($product && $quantity > 0) {
                    try { app(InventoryLocationCapacityService::class)->assertCanReceive($location, $product, $quantity); }
                    catch (\RuntimeException) { $physicalCapacityOk = false; }
                }
                $location->setAttribute('can_receive', ($available === null || $available + 0.000001 >= $quantity) && $physicalCapacityOk);
                return $location;
            })
            ->filter(fn (InventoryLocation $location): bool => $location->can_receive)
            ->sortBy(fn (InventoryLocation $location) => $location->available_capacity === null ? PHP_FLOAT_MAX : $location->available_capacity)
            ->values();

        $perPage = (int) ($data['per_page'] ?? 50);
        $page = max(1, (int) $request->input('page', 1));
        $slice = $locations->forPage($page, $perPage)->values();
        return response()->json(['data' => $slice, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $locations->count()]]);
    }

    public function putawayTasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,in_transit,partially_received,received,cancelled'],
            'product_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for warehouse put-away tasks.');
        $tasks = $this->companyScope(InventoryTransfer::with(['creator:id,name', 'lines.product', 'lines.sourceLocation.warehouse', 'lines.destinationLocation.warehouse']), $companyId)
            ->where('operation_type', 'putaway')
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($query, $productId) => $query->whereHas('lines', fn ($lineQuery) => $lineQuery->where('product_id', $productId)))
            ->when($data['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->whereHas('lines.sourceLocation', fn ($locationQuery) => $locationQuery->where('warehouse_id', $warehouseId)))
            ->latest('id')->paginate((int) ($data['per_page'] ?? 50));
        return response()->json([
            'data' => $tasks->getCollection(),
            'meta' => ['current_page' => $tasks->currentPage(), 'per_page' => $tasks->perPage(), 'total' => $tasks->total(), 'last_page' => $tasks->lastPage()],
        ]);
    }

    public function createPutawayTask(Request $request): JsonResponse
    {
        if (!$request->user()?->tokenCan('inventory:write') && !$request->user()?->tokenCan('warehouse:write') && !$request->user()?->tokenCan('integration:write')) {
            abort(403, 'This token cannot create warehouse put-away tasks.');
        }
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'source_location_id' => ['required', 'integer'],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id'],
            'product_scan_code' => ['nullable', 'string', 'max:120'],
            'source_location_code' => ['nullable', 'string', 'max:120'],
            'destination_location_code' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(InventoryTransfer::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => 'duplicate_ignored']);
        }

        $locations = InventoryLocation::with(['warehouse', 'barcodes'])->whereIn('id', [$data['source_location_id'], $data['destination_location_id']])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->get()->keyBy('id');
        if ($locations->count() !== 2) abort(422, 'Both locations must belong to the authenticated company.');
        $source = $locations[$data['source_location_id']];
        $destination = $locations[$data['destination_location_id']];
        $product = Product::whereKey($data['product_id'])->firstOrFail();
        if (!empty($data['product_scan_code'])) {
            $scanCode = trim($data['product_scan_code']);
            $matches = $product->sku === $scanCode || $product->barcode === $scanCode || $product->barcodes()->where('code', $scanCode)->exists();
            if (!$matches) abort(422, 'Product scan code does not match the requested product.');
        }
        if (!empty($data['source_location_code']) && !$this->locationScanMatches($source, $data['source_location_code'])) abort(422, 'Source location scan code does not match the requested location.');
        if (!empty($data['destination_location_code']) && !$this->locationScanMatches($destination, $data['destination_location_code'])) abort(422, 'Destination location scan code does not match the requested location.');
        if ((int) $source->warehouse_id !== (int) $destination->warehouse_id) abort(422, 'Put-away source and destination must belong to the same warehouse.');
        if (!in_array($destination->type, ['bin', 'shelf', 'rack'], true)) abort(422, 'Put-away destination must be a rack, shelf, or bin.');
        if (app(InventoryAvailabilityService::class)->available($product, false, (int) $source->id, $companyId) + 0.000001 < (float) $data['quantity']) abort(422, 'Put-away quantity exceeds available source stock.');
        app(InventoryLocationCapacityService::class)->assertCanReceive($destination, $product, (float) $data['quantity']);

        $transfer = DB::transaction(function () use ($request, $data, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'external_reference' => $data['external_reference'] ?? null,
                'operation_type' => 'putaway',
                'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_transfer', 'PUT-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'date' => $data['date'] ?? now()->toDateString(),
                'description' => $data['description'] ?? 'Put-away task created through warehouse integration.',
                'created_by' => $request->user()?->id,
                'status' => 'pending',
            ]);
            InventoryTransferLine::create([
                'transfer_id' => $transfer->id,
                'product_id' => $data['product_id'],
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? null,
            ]);
            app(AuditService::class)->record('put_away.task_created', $transfer, null, $transfer->toArray());
            if (!empty($data['product_scan_code']) || !empty($data['source_location_code']) || !empty($data['destination_location_code'])) {
                app(AuditService::class)->record('put_away.scan_validated', $transfer, null, collect($data)->only(['product_scan_code', 'source_location_code', 'destination_location_code'])->all());
            }
            return $transfer;
        });
        return response()->json(['data' => $transfer->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => 'pending_approval'], 201);
    }

    private function locationScanMatches(InventoryLocation $location, string $scanCode): bool
    {
        $scanCode = trim($scanCode);
        return $location->code === $scanCode || $location->barcodes->contains('code', $scanCode);
    }

    public function createTransfer(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
            'date' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000'],
            'carrier_name' => ['nullable', 'string', 'max:255'], 'tracking_number' => ['nullable', 'string', 'max:255'],
            'expected_arrival' => ['nullable', 'date', 'after_or_equal:date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'lines.*.source_location_id' => ['required', 'integer'], 'lines.*.destination_location_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(InventoryTransfer::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => 'duplicate_ignored']);
        }
        $locationIds = collect($data['lines'])->flatMap(fn (array $line): array => [(int) $line['source_location_id'], (int) $line['destination_location_id']])->unique()->values();
        $locations = InventoryLocation::whereIn('id', $locationIds)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->get()->keyBy('id');
        if ($locations->count() !== $locationIds->count()) abort(422, 'Every transfer location must belong to the authenticated company.');
        foreach ($data['lines'] as $line) {
            if ((int) $line['source_location_id'] === (int) $line['destination_location_id']) abort(422, 'Transfer source and destination must be different.');
        }
        $transfer = DB::transaction(function () use ($request, $data, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_transfer', 'TRF-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'date' => $data['date'], 'description' => $data['description'] ?? null, 'carrier_name' => $data['carrier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null, 'expected_arrival' => $data['expected_arrival'] ?? null,
                'status' => 'pending', 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $line) InventoryTransferLine::create([
                'transfer_id' => $transfer->id, 'product_id' => $line['product_id'], 'source_location_id' => $line['source_location_id'],
                'destination_location_id' => $line['destination_location_id'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? null,
            ]);
            app(AuditService::class)->record('inventory_transfer.created', $transfer, null, $transfer->toArray());
            return $transfer;
        });
        return response()->json(['data' => $transfer->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => 'pending_approval'], 201);
    }

    public function transferReconciliation(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $transfer = $this->companyScope(InventoryTransfer::with(['lines.product', 'lines.sourceLocation', 'lines.destinationLocation']), $companyId)->findOrFail($id);
        $movements = InventoryMovement::where('reference_type', InventoryTransfer::class)->where('reference_id', $transfer->id)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get();
        $rows = $transfer->lines->map(function (InventoryTransferLine $line) use ($movements): array {
            $dispatch = (float) $movements->where('product_id', $line->product_id)->where('location_id', $line->source_location_id)->where('movement_type', 'transfer_out')->sum('quantity');
            $receipt = (float) $movements->where('product_id', $line->product_id)->where('location_id', $line->destination_location_id)->where('movement_type', 'transfer_in')->sum('quantity');
            $expected = (float) $line->quantity;
            $received = (float) ($line->received_quantity ?? 0);
            $status = $dispatch <= 0.000001 && $receipt <= 0.000001
                ? 'not_posted'
                : (abs($dispatch - $expected) > 0.000001 || abs($receipt - $received) > 0.000001
                    ? 'variance'
                    : 'reconciled');
            return [
                'line_id' => (int) $line->id, 'product_id' => (int) $line->product_id, 'product_name' => $line->product?->name,
                'source_location_id' => (int) $line->source_location_id, 'source_location_code' => $line->sourceLocation?->code,
                'destination_location_id' => (int) $line->destination_location_id, 'destination_location_code' => $line->destinationLocation?->code,
                'planned_quantity' => $expected, 'received_quantity' => $received, 'dispatched_ledger_quantity' => $dispatch,
                'received_ledger_quantity' => $receipt, 'dispatch_variance' => $dispatch - $expected, 'receipt_variance' => $receipt - $received,
                'reconciliation_status' => $status,
            ];
        })->values();
        return response()->json([
            'data' => $rows,
            'transfer' => $transfer->only(['id', 'transfer_no', 'external_reference', 'status', 'variance_status', 'date', 'dispatched_at', 'received_at']),
            'summary' => ['line_count' => $rows->count(), 'planned_quantity' => round((float) $rows->sum('planned_quantity'), 6), 'received_quantity' => round((float) $rows->sum('received_quantity'), 6), 'dispatched_ledger_quantity' => round((float) $rows->sum('dispatched_ledger_quantity'), 6), 'received_ledger_quantity' => round((float) $rows->sum('received_ledger_quantity'), 6), 'variance_line_count' => $rows->where('reconciliation_status', 'variance')->count()],
            'read_only' => true,
        ]);
    }

    public function approveTransfer(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        try {
            $transfer = DB::transaction(function () use ($id): InventoryTransfer {
                $transfer = $this->companyScope(InventoryTransfer::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'pending') throw new \RuntimeException('Only pending transfers can be approved.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $transfer->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('inventory_transfer.approved', $transfer, ['status' => 'pending'], ['status' => 'approved']);
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $transfer->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => $transfer->status]);
    }

    public function dispatchTransfer(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        try {
            $transfer = DB::transaction(function () use ($id): InventoryTransfer {
                $transfer = $this->companyScope(InventoryTransfer::with('lines'), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'approved') throw new \RuntimeException('Only approved transfers can be dispatched.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                foreach ($transfer->lines as $line) {
                    $product = $this->companyScope(Product::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($line->product_id);
                    if (app(InventoryAvailabilityService::class)->available($product, false, $line->source_location_id, $transfer->company_id) < (float) $line->quantity) throw new \RuntimeException('Insufficient source stock for '.$product->name.'.');
                    $serials = app(SerialLifecycleService::class)->reserveForTransfer($product, (float) $line->quantity, (int) $line->source_location_id);
                    if ($serials->isNotEmpty()) foreach ($serials as $serial) {
                        InventoryTransferSerial::create(['transfer_line_id' => $line->id, 'serial_id' => $serial->id]);
                        $movement = app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', 1, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Warehouse transfer dispatch', null, $serial->batch_id, $serial->id);
                        foreach ($movement->allocations as $allocation) InventoryTransferAllocation::create(['transfer_line_id' => $line->id, 'batch_id' => $allocation->batch_id, 'serial_id' => $allocation->serial_id, 'quantity' => $allocation->quantity]);
                    } elseif ((float) $line->quantity > 0) {
                        $movement = app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', (float) $line->quantity, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Warehouse transfer dispatch');
                        foreach ($movement->allocations as $allocation) InventoryTransferAllocation::create(['transfer_line_id' => $line->id, 'batch_id' => $allocation->batch_id, 'serial_id' => $allocation->serial_id, 'quantity' => $allocation->quantity]);
                    }
                }
                $transfer->update(['status' => 'in_transit', 'dispatched_by' => auth()->id(), 'dispatched_at' => now()]);
                app(AuditService::class)->record('inventory_transfer.dispatched', $transfer, ['status' => 'approved'], ['status' => 'in_transit']);
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $transfer->load('lines.product', 'lines.transferSerials.serial', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => $transfer->status]);
    }

    public function receiveTransfer(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['received_quantities' => ['nullable', 'array'], 'received_quantities.*' => ['numeric', 'min:0'], 'receiving_note' => ['nullable', 'string', 'max:2000']]);
        try {
            $transfer = DB::transaction(function () use ($id, $data): InventoryTransfer {
                $transfer = $this->companyScope(InventoryTransfer::with(['lines.transferSerials.serial', 'lines.allocations']), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if (!in_array($transfer->status, ['in_transit', 'partially_received'], true)) throw new \RuntimeException('Only in-transit transfers can be received.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $receivedQuantities = $data['received_quantities'] ?? [];
                $allReceived = true;
                foreach ($transfer->lines as $line) {
                    $already = (float) ($line->received_quantity ?? 0); $remaining = max(0, (float) $line->quantity - $already);
                    $received = array_key_exists((string) $line->id, $receivedQuantities) ? (float) $receivedQuantities[(string) $line->id] : (array_key_exists($line->id, $receivedQuantities) ? (float) $receivedQuantities[$line->id] : $remaining);
                    if ($received > $remaining + 0.000001) throw new \RuntimeException('Received quantity exceeds the remaining transfer quantity.');
                    $serialRows = $line->transferSerials->whereNull('received_at')->take((int) round($received));
                    if ($received > 0 && $line->transferSerials->isNotEmpty() && $serialRows->count() !== (int) round($received)) throw new \RuntimeException('Received serial quantity does not match the transfer allocation.');
                    foreach ($serialRows as $serialRow) { $serialRow->serial->update(['status' => 'available', 'location_id' => $line->destination_location_id]); $serialRow->update(['received_at' => now()]); app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', 1, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt', null, $serialRow->serial->batch_id, $serialRow->serial_id); $allocation = InventoryTransferAllocation::where('transfer_line_id', $line->id)->where('serial_id', $serialRow->serial_id)->lockForUpdate()->first(); if ($allocation) { $allocation->received_quantity = min((float) $allocation->quantity, (float) $allocation->received_quantity + 1); $allocation->save(); } }
                    if ($serialRows->isEmpty() && $received > 0) {
                        $remainingReceipt = $received;
                        $allocations = InventoryTransferAllocation::where('transfer_line_id', $line->id)->whereColumn('received_quantity', '<', 'quantity')->orderBy('id')->lockForUpdate()->get();
                        foreach ($allocations as $allocation) {
                            if ($remainingReceipt <= 0.000001) break;
                            $open = (float) $allocation->quantity - (float) $allocation->received_quantity;
                            $quantity = min($remainingReceipt, $open);
                            if ($quantity <= 0.000001) continue;
                            app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', $quantity, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt', null, $allocation->batch_id);
                            $allocation->received_quantity = (float) $allocation->received_quantity + $quantity;
                            $allocation->save();
                            $remainingReceipt -= $quantity;
                        }
                        if ($remainingReceipt > 0.000001) app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', $remainingReceipt, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt');
                    }
                    $line->update(['received_quantity' => $already + $received]);
                    if ($already + $received < (float) $line->quantity - 0.000001) $allReceived = false;
                }
                $transfer->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_by' => auth()->id(), 'received_at' => now(), 'receiving_note' => $data['receiving_note'] ?? null]);
                app(AuditService::class)->record('inventory_transfer.received', $transfer, null, ['status' => $transfer->status, 'receiving_note' => $transfer->receiving_note]);
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $transfer->load('lines.product', 'lines.transferSerials.serial', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => $transfer->status]);
    }

    public function resolveTransferVariance(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate([
            'resolution' => ['required', 'in:accepted,waived'],
            'variance_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $transfer = DB::transaction(function () use ($id, $data): InventoryTransfer {
                $transfer = $this->companyScope(InventoryTransfer::with('lines.product'), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'received' || $transfer->variance_status !== 'pending') {
                    throw new \RuntimeException('Only a fully received transfer with a pending variance can be resolved.');
                }
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $before = $transfer->only(['variance_status', 'variance_reason', 'variance_resolved_by', 'variance_resolved_at']);
                $transfer->update(['variance_status' => $data['resolution'], 'variance_reason' => $data['variance_reason'], 'variance_resolved_by' => auth()->id(), 'variance_resolved_at' => now()]);
                app(AutomaticAccountingService::class)->postTransferShortage($transfer);
                app(AuditService::class)->record('inventory_transfer.variance_resolved', $transfer, $before, $transfer->only(['variance_status', 'variance_reason', 'variance_resolved_by', 'variance_resolved_at']));
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $transfer->load('lines.product', 'lines.sourceLocation', 'lines.destinationLocation', 'lines.allocations.batch', 'lines.allocations.serial'), 'status' => $transfer->status]);
    }

    private function assertWriteAccess(Request $request): void
    {
        if (!$request->user()?->tokenCan('inventory:write') && !$request->user()?->tokenCan('warehouse:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify warehouse transfers.');
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
