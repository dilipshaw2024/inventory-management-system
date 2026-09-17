<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillOfMaterial;
use App\Models\BomLine;
use App\Models\BomByproduct;
use App\Models\ProductionOrder;
use App\Models\ProductionOperation;
use App\Models\Branch;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use App\Services\ProductionService;
use App\Services\ProductionOperationService;
use App\Services\AuditService;
use App\Services\BomRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Services\ProductionCapacityService;
use App\Services\ProductionSuggestionService;
use App\Services\ProductionSchedulingService;

class ManufacturingIntegrationController extends Controller
{
    public function productionSuggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['bom_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for production planning.');
        $suggestions = app(ProductionSuggestionService::class)->forCompany((int) $companyId, $data['bom_id'] ?? null);
        $page = max(1, $request->integer('page', 1)); $perPage = (int) ($data['per_page'] ?? 50);
        $items = $suggestions->forPage($page, $perPage)->map(fn (array $row): array => [
            'bom' => $row['bom']->only(['id', 'code', 'name', 'version', 'output_quantity']),
            'product' => $row['bom']->product?->only(['id', 'name', 'sku']),
            'target' => $row['target'], 'available' => $row['available'], 'open_purchase_quantity' => $row['open_purchase_quantity'], 'open_quantity' => $row['open_quantity'], 'net_available' => $row['net_available'],
            'suggested' => $row['suggested'], 'max_build' => $row['max_build'], 'shortages' => $row['shortages'],
        ])->values();
        return response()->json(['data' => $items, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $suggestions->count(), 'last_page' => max(1, (int) ceil($suggestions->count() / $perPage))]]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('production_orders', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'bom_id' => ['required', 'integer', $owned('bills_of_materials')], 'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'planned_quantity' => ['required', 'numeric', 'gt:0'], 'planned_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'], 'output_batch_no' => ['nullable', 'string', 'max:100'],
            'output_serial_numbers' => ['nullable', 'string', 'max:10000'], 'output_manufacturing_date' => ['nullable', 'date'],
            'output_expiry_date' => ['nullable', 'date'], 'output_best_before_date' => ['nullable', 'date'],
            'output_warranty_until' => ['nullable', 'date'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(ProductionOrder::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('bom', 'product'), 'status' => 'duplicate_ignored']);
        }
        $bom = $this->companyScope(BillOfMaterial::query(), $companyId)->findOrFail($data['bom_id']);
        $plannedDate = Carbon::parse($data['planned_date'])->toDateString();
        if (!$bom->is_active || ($bom->effective_from && $bom->effective_from->gt($plannedDate)) || ($bom->effective_until && $bom->effective_until->lt($plannedDate))) abort(422, 'The selected BOM is inactive or not effective on the planned production date.');
        $bomSnapshot = app(\App\Services\BomExplosionService::class)->snapshot($bom, $companyId, $plannedDate);
        if (!empty($data['location_id']) && !$this->companyScope(\App\Models\InventoryLocation::query(), $companyId)->whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');
        $order = DB::transaction(function () use ($data, $bom, $bomSnapshot, $companyId, $request): ProductionOrder {
            return ProductionOrder::create($data + [
                'company_id' => $companyId, 'product_id' => $bom->product_id, 'bom_version' => $bom->version ?: '1', 'bom_snapshot' => $bomSnapshot,
                'order_no' => app(NumberingSequenceService::class)->nextOrFallback('production_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'created_by' => $request->user()?->id, 'status' => 'draft',
            ]);
        });
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => 'pending_approval'], 201);
    }

    public function releaseOrder(int $id): JsonResponse
    {
        $order = app(ProductionService::class)->release($id);
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => $order->status]);
    }

    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $order = app(ProductionService::class)->cancel($id, $data['cancellation_reason']);
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => $order->status]);
    }

    public function completeOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['produced_quantity' => ['nullable', 'numeric', 'gt:0']]);
        $order = app(ProductionService::class)->complete($id, array_key_exists('produced_quantity', $data) ? (float) $data['produced_quantity'] : null);
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => $order->status]);
    }

    public function startOperation(int $id): JsonResponse
    {
        $operation = app(ProductionOperationService::class)->start($id);
        return response()->json(['data' => $operation->load('order', 'workCenter'), 'status' => $operation->status]);
    }

    public function completeOperation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'completed_quantity' => ['required', 'numeric', 'gt:0'], 'actual_setup_minutes' => ['nullable', 'numeric', 'min:0'],
            'actual_run_minutes' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $operation = app(ProductionOperationService::class)->complete($id, (float) $data['completed_quantity'], $data['actual_setup_minutes'] ?? null, $data['actual_run_minutes'] ?? null, $data['notes'] ?? null);
        return response()->json(['data' => $operation->load('order', 'workCenter'), 'status' => $operation->status]);
    }

    public function scheduleOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['start_at' => ['nullable', 'date']]);
        $order = $this->companyScope(ProductionOrder::query())->findOrFail($id);
        $scheduled = app(ProductionSchedulingService::class)->schedule($order, $data['start_at'] ?? null);
        return response()->json(['data' => $scheduled->load('operations.workCenter'), 'status' => 'scheduled']);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,released,in_progress,completed,cancelled'],
            'planned_from' => ['nullable', 'date'], 'planned_to' => ['nullable', 'date', 'after_or_equal:planned_from'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $orders = $this->companyScope(ProductionOrder::with([
            'bom.lines.component', 'bom.byproducts.product', 'product:id,name,sku', 'location:id,code,name',
            'creator:id,name,email', 'operations.workCenter:id,code,name', 'operations.routingOperation',
            'operations.starter:id,name,email', 'operations.completer:id,name,email',
        ]), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['planned_from'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($data['planned_to'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($orders, $request, 'manufacturing.orders', (int) ($data['per_page'] ?? 50));
    }

    public function costs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:draft,released,in_progress,completed,cancelled'],
        ]);
        $companyId = $request->user()?->company_id;
        $rows = $this->companyScope(ProductionOrder::with('product:id,name,sku')->select('product_id')
            ->selectRaw('COUNT(*) AS order_count, SUM(planned_quantity) AS planned_quantity, SUM(completed_quantity) AS completed_quantity, SUM(material_cost) AS material_cost, SUM(operation_cost) AS operation_cost, SUM(byproduct_cost) AS byproduct_cost, SUM(production_cost) AS production_cost'), $companyId)
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->groupBy('product_id')->orderBy('product_id')->get();
        return response()->json(['data' => $rows, 'totals' => ['order_count' => (int) $rows->sum('order_count'), 'planned_quantity' => (float) $rows->sum('planned_quantity'), 'completed_quantity' => (float) $rows->sum('completed_quantity'), 'material_cost' => (float) $rows->sum('material_cost'), 'operation_cost' => (float) $rows->sum('operation_cost'), 'byproduct_cost' => (float) $rows->sum('byproduct_cost'), 'production_cost' => (float) $rows->sum('production_cost')]]);
    }

    public function workCenterUtilization(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $companyId = $request->user()?->company_id;
        $rows = $this->companyScope(ProductionOperation::with('workCenter:id,code,name,labor_rate,machine_rate')->select('work_center_id')
            ->selectRaw('COUNT(*) AS operation_count, SUM(completed_quantity) AS completed_quantity, SUM(COALESCE(actual_setup_minutes, 0)) AS setup_minutes, SUM(COALESCE(actual_run_minutes, 0)) AS run_minutes'), $companyId)
            ->where('status', 'completed')->whereNotNull('work_center_id')
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('completed_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('completed_at', '<=', $date))
            ->groupBy('work_center_id')->orderBy('work_center_id')->get()
            ->map(function (ProductionOperation $operation): array {
                $setupMinutes = (float) $operation->setup_minutes;
                $runMinutes = (float) $operation->run_minutes;
                $hours = ($setupMinutes + $runMinutes) / 60;
                $rate = (float) ($operation->workCenter?->labor_rate ?? 0) + (float) ($operation->workCenter?->machine_rate ?? 0);
                return ['work_center' => $operation->workCenter, 'operation_count' => (int) $operation->operation_count, 'completed_quantity' => (float) $operation->completed_quantity, 'setup_minutes' => $setupMinutes, 'run_minutes' => $runMinutes, 'utilized_hours' => $hours, 'operation_cost' => $hours * $rate];
            })->values();
        return response()->json(['data' => $rows, 'totals' => ['operation_count' => (int) $rows->sum('operation_count'), 'completed_quantity' => (float) $rows->sum('completed_quantity'), 'setup_minutes' => (float) $rows->sum('setup_minutes'), 'run_minutes' => (float) $rows->sum('run_minutes'), 'utilized_hours' => (float) $rows->sum('utilized_hours'), 'operation_cost' => (float) $rows->sum('operation_cost')]]);
    }

    public function capacityLoad(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? Carbon::today()->toDateString();
        $to = $data['to'] ?? Carbon::today()->addDays(30)->toDateString();
        $operations = ProductionOperation::with(['workCenter', 'routingOperation', 'order:id,planned_date,status'])
            ->whereNotIn('status', ['completed', 'skipped', 'cancelled'])
            ->whereHas('order', fn ($query) => $query->whereBetween('planned_date', [$from, $to])->whereIn('status', ['released', 'in_progress', 'planned']))
            ->whereNotNull('work_center_id')->get();
        $rows = $operations->groupBy(fn (ProductionOperation $operation): string => optional($operation->order?->planned_date)->toDateString().'|'.$operation->work_center_id)
            ->map(function ($group): array {
                $operation = $group->first();
                $loadHours = app(ProductionCapacityService::class)->plannedLoadHours($group);
                $capacity = (float) ($operation->workCenter?->capacity_hours_per_day ?? 0);
                $utilization = app(ProductionCapacityService::class)->utilizationPercent($loadHours, $capacity);
                return ['date' => optional($operation->order?->planned_date)->toDateString(), 'work_center' => $operation->workCenter, 'operation_count' => $group->count(), 'planned_quantity' => (float) $group->sum('planned_quantity'), 'setup_minutes' => (float) $group->sum(fn ($item) => (float) ($item->routingOperation?->setup_minutes ?? 0)), 'planned_load_hours' => $loadHours, 'capacity_hours' => $capacity > 0 ? $capacity : null, 'utilization_percent' => $utilization, 'overloaded' => $capacity > 0 && $loadHours > $capacity];
            })->values()->sortBy(fn (array $row): string => ($row['date'] ?? '').'|'.(string) data_get($row, 'work_center.id', ''))->values();
        return response()->json(['data' => $rows, 'filters' => ['from' => $from, 'to' => $to], 'totals' => ['planned_load_hours' => (float) $rows->sum('planned_load_hours'), 'capacity_hours' => (float) $rows->sum('capacity_hours'), 'overloaded_slots' => $rows->where('overloaded', true)->count()]]);
    }

    public function wip(Request $request): JsonResponse
    {
        $data = $request->validate(['planned_from' => ['nullable', 'date'], 'planned_to' => ['nullable', 'date', 'after_or_equal:planned_from']]);
        $orders = $this->companyScope(ProductionOrder::with(['product:id,name,sku', 'bom:id,code,version', 'location:id,code,name']), $request->user()?->company_id)
            ->whereIn('status', ['released', 'in_progress'])
            ->when($data['planned_from'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($data['planned_to'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->orderBy('planned_date')->orderBy('id')->get()
            ->map(function (ProductionOrder $order): array {
                $planned = (float) $order->planned_quantity;
                $completed = (float) $order->completed_quantity;
                return ['id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status, 'planned_date' => optional($order->planned_date)->toDateString(), 'product' => $order->product, 'bom' => $order->bom, 'location' => $order->location, 'planned_quantity' => $planned, 'completed_quantity' => $completed, 'remaining_quantity' => max(0, $planned - $completed), 'completion_percent' => $planned > 0 ? min(100, ($completed / $planned) * 100) : 0, 'material_cost' => (float) $order->material_cost, 'operation_cost' => (float) $order->operation_cost, 'byproduct_cost' => (float) $order->byproduct_cost, 'production_cost' => (float) $order->production_cost];
            })->values();
        return response()->json(['data' => $orders, 'totals' => ['order_count' => $orders->count(), 'planned_quantity' => (float) $orders->sum('planned_quantity'), 'completed_quantity' => (float) $orders->sum('completed_quantity'), 'remaining_quantity' => (float) $orders->sum('remaining_quantity'), 'production_cost' => (float) $orders->sum('production_cost')]]);
    }

    public function boms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'], 'version' => ['nullable', 'string', 'max:50'], 'as_of' => ['nullable', 'date'], 'is_active' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $boms = $this->companyScope(BillOfMaterial::with(['product:id,name,sku', 'lines.component:id,name,sku', 'byproducts.product:id,name,sku']), $request->user()?->company_id)
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['version'] ?? null, fn ($query, $version) => $query->where('version', $version))
            ->when($data['as_of'] ?? null, function ($query, $date): void {
                $query->where(fn ($scope) => $scope->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                    ->where(fn ($scope) => $scope->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
            })
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($boms, $request, 'manufacturing.boms', (int) ($data['per_page'] ?? 50));
    }

    public function storeBom(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('bills_of_materials', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'product_id' => ['required', 'integer', $owned('products')], 'code' => ['required', 'string', 'max:50'],
            'version' => ['nullable', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'output_quantity' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.component_product_id' => ['required', 'integer', $owned('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.scrap_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'byproducts' => ['nullable', 'array'], 'byproducts.*.product_id' => ['required', 'integer', $owned('products')],
            'byproducts.*.quantity' => ['required', 'numeric', 'gt:0'], 'byproducts.*.cost_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(BillOfMaterial::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['product', 'lines.component', 'byproducts.product']), 'status' => 'duplicate_ignored']);
        }
        $componentIds = array_map(fn (array $line): int => (int) $line['component_product_id'], $data['lines']);
        if (in_array((int) $data['product_id'], $componentIds, true) || count($componentIds) !== count(array_unique($componentIds))) abort(422, 'BOM components must be distinct and cannot include the finished product.');
        $shares = array_sum(array_map(fn (array $line): float => (float) ($line['cost_share_percent'] ?? 0), $data['byproducts'] ?? []));
        if ($shares > 100.000001) abort(422, 'By-product cost shares cannot exceed 100%.');
        if ($data['is_active'] ?? true) app(BomRevisionService::class)->assertNoActiveOverlap((int) $data['product_id'], $data['effective_from'] ?? null, $data['effective_until'] ?? null, $companyId);
        $bom = DB::transaction(function () use ($data, $companyId, $request): BillOfMaterial {
            $bom = BillOfMaterial::create(['company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'product_id' => $data['product_id'], 'code' => $data['code'], 'version' => $data['version'] ?? '1', 'name' => $data['name'], 'output_quantity' => $data['output_quantity'], 'effective_from' => $data['effective_from'] ?? null, 'effective_until' => $data['effective_until'] ?? null, 'is_active' => $data['is_active'] ?? true]);
            foreach ($data['lines'] as $line) BomLine::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'component_product_id' => $line['component_product_id'], 'quantity' => $line['quantity'], 'scrap_percent' => $line['scrap_percent'] ?? 0]);
            foreach ($data['byproducts'] ?? [] as $line) BomByproduct::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'cost_share_percent' => $line['cost_share_percent'] ?? 0]);
            app(AuditService::class)->record('bom.created', $bom, null, $bom->toArray() + ['api' => true, 'created_by' => $request->user()?->id]);
            return $bom;
        });
        return response()->json(['data' => $bom->load(['product', 'lines.component', 'byproducts.product']), 'status' => 'created'], 201);
    }

    public function updateBom(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $bom = $this->companyScope(BillOfMaterial::query(), $companyId)->findOrFail($id);
        if (ProductionOrder::where('bom_id', $bom->id)->exists()) abort(422, 'This BOM revision is immutable because it has been referenced by a production order. Create a new version instead.');
        $data = $request->validate([
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('bills_of_materials', 'external_reference')->ignore($bom->id)->where(fn ($query) => $query->where('company_id', $companyId))],
            'product_id' => ['sometimes', 'integer', $owned('products')], 'code' => ['sometimes', 'string', 'max:50'], 'version' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'], 'output_quantity' => ['sometimes', 'numeric', 'gt:0'], 'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date'], 'is_active' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array', 'min:1'], 'lines.*.component_product_id' => ['required_with:lines', 'integer', $owned('products')],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'], 'lines.*.scrap_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'byproducts' => ['sometimes', 'array'], 'byproducts.*.product_id' => ['required', 'integer', $owned('products')],
            'byproducts.*.quantity' => ['required', 'numeric', 'gt:0'], 'byproducts.*.cost_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (array_key_exists('effective_from', $data) && array_key_exists('effective_until', $data) && $data['effective_from'] && $data['effective_until'] && $data['effective_until'] < $data['effective_from']) abort(422, 'The effective end date must not precede the start date.');
        $productId = (int) ($data['product_id'] ?? $bom->product_id);
        if (array_key_exists('lines', $data)) {
            $componentIds = array_map(fn (array $line): int => (int) $line['component_product_id'], $data['lines']);
            if (in_array($productId, $componentIds, true) || count($componentIds) !== count(array_unique($componentIds))) abort(422, 'BOM components must be distinct and cannot include the finished product.');
        }
        if (array_key_exists('byproducts', $data) && array_sum(array_map(fn (array $line): float => (float) ($line['cost_share_percent'] ?? 0), $data['byproducts'])) > 100.000001) abort(422, 'By-product cost shares cannot exceed 100%.');
        if ($data['is_active'] ?? $bom->is_active) app(BomRevisionService::class)->assertNoActiveOverlap($productId, $data['effective_from'] ?? $bom->effective_from?->toDateString(), $data['effective_until'] ?? $bom->effective_until?->toDateString(), $companyId, $bom->id);
        $updated = DB::transaction(function () use ($data, $bom, $productId, $companyId): BillOfMaterial {
            $bom->update(array_filter(['external_reference' => $data['external_reference'] ?? $bom->external_reference, 'product_id' => $productId, 'code' => $data['code'] ?? $bom->code, 'version' => $data['version'] ?? ($bom->version ?: '1'), 'name' => $data['name'] ?? $bom->name, 'output_quantity' => $data['output_quantity'] ?? $bom->output_quantity, 'effective_from' => $data['effective_from'] ?? $bom->effective_from, 'effective_until' => $data['effective_until'] ?? $bom->effective_until, 'is_active' => $data['is_active'] ?? $bom->is_active], fn ($value): bool => $value !== null));
            if (array_key_exists('lines', $data)) {
                $bom->lines()->delete();
                foreach ($data['lines'] as $line) BomLine::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'component_product_id' => $line['component_product_id'], 'quantity' => $line['quantity'], 'scrap_percent' => $line['scrap_percent'] ?? 0]);
            }
            if (array_key_exists('byproducts', $data)) {
                $bom->byproducts()->delete();
                foreach ($data['byproducts'] as $line) BomByproduct::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'cost_share_percent' => $line['cost_share_percent'] ?? 0]);
            }
            app(AuditService::class)->record('bom.updated', $bom, null, $bom->fresh()->toArray() + ['api' => true]);
            return $bom->fresh(['product', 'lines.component', 'byproducts.product']);
        });
        return response()->json(['data' => $updated, 'status' => 'updated']);
    }

    public function deactivateBom(int $id): JsonResponse
    {
        $bom = $this->companyScope(BillOfMaterial::query(), auth()->user()?->company_id)->findOrFail($id);
        if (ProductionOrder::where('bom_id', $bom->id)->whereIn('status', ['draft', 'released', 'in_progress'])->exists()) abort(422, 'This BOM cannot be deactivated while it is used by an open production order.');
        if (!$bom->is_active) return response()->json(['data' => $bom, 'status' => 'already_inactive']);
        $before = ['is_active' => true];
        $bom->update(['is_active' => false]);
        app(AuditService::class)->record('bom.deactivated', $bom, $before, ['is_active' => false]);
        return response()->json(['data' => $bom->fresh(), 'status' => 'deactivated']);
    }

    public function operations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:30'], 'production_order_id' => ['nullable', 'integer'],
            'work_center_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $operations = $this->companyScope(ProductionOperation::with([
            'order:id,company_id,order_no,product_id,status,planned_date', 'order.product:id,name,sku',
            'routingOperation:id,routing_id,sequence,operation,setup_minutes,run_minutes', 'workCenter:id,code,name',
            'starter:id,name,email', 'completer:id,name,email',
        ]), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['production_order_id'] ?? null, fn ($query, $id) => $query->where('production_order_id', $id))
            ->when($data['work_center_id'] ?? null, fn ($query, $id) => $query->where('work_center_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(\App\Services\IntegrationCursorService::class)->paginate($operations, $request, 'manufacturing.operations', (int) ($data['per_page'] ?? 50));
    }

    private function companyScope($query, ?int $companyId = null)
    {
        $companyId ??= auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
