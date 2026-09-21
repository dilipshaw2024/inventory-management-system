<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillOfMaterial;
use App\Models\BomLine;
use App\Models\BomByproduct;
use App\Models\ProductionOrder;
use App\Models\ProductionOperation;
use App\Models\ProductionScrapRecord;
use App\Models\InventoryMovement;
use App\Models\InventoryCostConsumption;
use App\Models\Branch;
use App\Models\Product;
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
use App\Services\UomConversionService;
use App\Services\ProductionScrapService;
use App\Services\ProductionVarianceService;

class ManufacturingIntegrationController extends Controller
{
    public function productionSuggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['bom_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for production planning.');
        $suggestions = app(ProductionSuggestionService::class)->forCompany((int) $companyId, $data['bom_id'] ?? null);
        $page = max(1, (int) $request->input('page', 1)); $perPage = (int) ($data['per_page'] ?? 50);
        $items = $suggestions->forPage($page, $perPage)->map(fn (array $row): array => [
            'bom' => $row['bom']->only(['id', 'code', 'name', 'version', 'output_quantity']),
            'product' => $row['bom']->product?->only(['id', 'name', 'sku']),
            'target' => $row['target'], 'available' => $row['available'], 'open_purchase_quantity' => $row['open_purchase_quantity'], 'open_quantity' => $row['open_quantity'], 'net_available' => $row['net_available'],
            'suggested' => $row['suggested'], 'max_build' => $row['max_build'], 'shortages' => $row['shortages'],
        ])->values();
        return response()->json(['data' => $items, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $suggestions->count(), 'last_page' => max(1, (int) ceil($suggestions->count() / $perPage))]]);
    }

    public function productionScrap(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'production_order_id' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->companyScope(ProductionScrapRecord::with(['productionOrder:id,order_no,status', 'product:id,name,sku', 'recoveryProduct:id,name,sku', 'location:id,code,name', 'creator:id,name,email', 'approver:id,name,email']), $companyId)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['production_order_id'] ?? null, fn ($q, $id) => $q->where('production_order_id', $id))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($data['updated_since'] ?? null, fn ($q, $date) => $q->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($query, $request, 'manufacturing.production_scrap', (int) ($data['per_page'] ?? 50));
    }

    public function productionScrapSummary(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'in:pending,approved,rejected'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer']]);
        $rows = $this->companyScope(ProductionScrapRecord::query(), $companyId)
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when(array_key_exists('location_id', $data), fn ($q) => $q->where('location_id', $data['location_id']))
            ->select('product_id', 'location_id')
            ->selectRaw('SUM(quantity) AS quantity, SUM(quantity * COALESCE(unit_cost, 0)) AS scrap_value, SUM(recovery_quantity * COALESCE(recovery_unit_cost, 0)) AS recovery_value, AVG(unit_cost) AS average_unit_cost, COUNT(*) AS record_count')
            ->groupBy('product_id', 'location_id')->with(['product:id,name,sku', 'location:id,code,name'])->orderBy('product_id')->get();
        return response()->json(['data' => $rows, 'totals' => ['quantity' => (float) $rows->sum('quantity'), 'scrap_value' => (float) $rows->sum('scrap_value'), 'recovery_value' => (float) $rows->sum('recovery_value'), 'net_loss' => (float) $rows->sum('scrap_value') - (float) $rows->sum('recovery_value'), 'record_count' => (int) $rows->sum('record_count')]]);
    }

    public function storeProductionScrap(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
            'production_order_id' => ['required', 'integer', $owned('production_orders')], 'product_id' => ['required', 'integer', $owned('products')],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)], 'quantity' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'numeric', 'min:0'], 'recovery_product_id' => ['nullable', 'integer', $owned('products'), 'different:product_id'], 'recovery_quantity' => ['nullable', 'numeric', 'gt:0'], 'recovery_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'string', 'max:100'], 'serial_numbers' => ['nullable', 'string', 'max:10000'], 'reason' => ['required', 'string', 'max:2000'],
        ]);
        $order = $this->companyScope(ProductionOrder::query(), $companyId)->findOrFail($data['production_order_id']);
        if (in_array($order->status, ['cancelled', 'closed'], true)) abort(422, 'Scrap cannot be recorded against a cancelled or closed production order.');
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(ProductionScrapRecord::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['productionOrder', 'product', 'location']), 'status' => 'duplicate_ignored']);
        }
        $scrap = ProductionScrapRecord::create($data + ['company_id' => $companyId, 'created_by' => $request->user()?->id, 'status' => 'pending']);
        app(AuditService::class)->record('production_scrap.created', $scrap, null, $scrap->toArray() + ['api' => true]);
        return response()->json(['data' => $scrap->load(['productionOrder', 'product', 'recoveryProduct', 'location']), 'status' => 'pending_approval'], 201);
    }

    public function approveProductionScrap(int $id): JsonResponse
    {
        return response()->json(['data' => app(ProductionScrapService::class)->approve($id), 'status' => 'approved']);
    }

    public function rejectProductionScrap(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['data' => app(ProductionScrapService::class)->reject($id, $data['reason']), 'status' => 'rejected']);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
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
        if (!$bom->is_active || ($bom->approval_status ?? 'approved') !== 'approved' || ($bom->effective_from && $bom->effective_from->gt($plannedDate)) || ($bom->effective_until && $bom->effective_until->lt($plannedDate))) abort(422, 'The selected BOM is inactive, unapproved, or not effective on the planned production date.');
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

    public function pauseOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['pause_reason' => ['required', 'string', 'max:2000']]);
        $order = app(ProductionService::class)->pause($id, $data['pause_reason']);
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => $order->status]);
    }

    public function resumeOrder(int $id): JsonResponse
    {
        $order = app(ProductionService::class)->resume($id);
        return response()->json(['data' => $order->load('bom', 'product'), 'status' => $order->status]);
    }

    public function closeOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['close_reason' => ['required', 'string', 'max:2000']]);
        $order = app(ProductionService::class)->close($id, $data['close_reason']);
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

    public function scheduleOrders(Request $request): JsonResponse
    {
        $data = $request->validate(['order_ids' => ['required', 'array', 'min:1', 'max:100'], 'order_ids.*' => ['integer'], 'start_at' => ['nullable', 'date'], 'dispatch_rule' => ['nullable', 'in:planned_date,shortest_processing_time,critical_ratio']]);
        $companyId = $request->user()?->company_id;
        $orders = $this->companyScope(ProductionOrder::query(), $companyId)->whereIn('id', $data['order_ids'])->get();
        if ($orders->count() !== count(array_unique($data['order_ids']))) abort(422, 'Every production order must belong to the current company.');
        $dispatchRule = $data['dispatch_rule'] ?? 'planned_date';
        $scheduled = app(ProductionSchedulingService::class)->scheduleMany($orders, $data['start_at'] ?? null, $dispatchRule)->map(fn (ProductionOrder $order): ProductionOrder => $order->load('operations.workCenter'));
        return response()->json(['data' => $scheduled, 'summary' => ['order_count' => $scheduled->count(), 'operation_count' => (int) $scheduled->sum(fn (ProductionOrder $order): int => $order->operations->count()), 'scheduled_count' => (int) $scheduled->sum(fn (ProductionOrder $order): int => $order->operations->where('schedule_status', 'scheduled')->count()), 'dispatch_rule' => $dispatchRule], 'status' => 'scheduled']);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,released,in_progress,paused,completed,closed,cancelled'],
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
            'status' => ['nullable', 'in:draft,released,in_progress,paused,completed,closed,cancelled'],
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

    public function productionVariance(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'in:draft,released,in_progress,paused,completed,closed,cancelled'], 'product_id' => ['nullable', 'integer'], 'order_id' => ['nullable', 'integer']]);
        $rows = app(ProductionVarianceService::class)->report((int) $request->user()?->company_id, $data['from'] ?? null, $data['to'] ?? null, $data['status'] ?? null, $data['product_id'] ?? null, $data['order_id'] ?? null);
        return response()->json(['data' => $rows, 'totals' => ['order_count' => $rows->count(), 'planned_quantity' => (float) $rows->sum('planned_quantity'), 'completed_quantity' => (float) $rows->sum('completed_quantity'), 'yield_variance' => (float) $rows->sum('yield_variance'), 'planned_material_cost' => (float) $rows->sum('planned_material_cost'), 'expected_material_cost_to_date' => (float) $rows->sum('expected_material_cost_to_date'), 'actual_material_cost' => (float) $rows->sum('actual_material_cost'), 'material_cost_variance' => (float) $rows->sum('material_cost_variance'), 'planned_operation_cost' => (float) $rows->sum('planned_operation_cost'), 'expected_operation_cost_to_date' => (float) $rows->sum('expected_operation_cost_to_date'), 'actual_operation_cost' => (float) $rows->sum('actual_operation_cost'), 'operation_cost_variance' => (float) $rows->sum('operation_cost_variance'), 'total_cost_variance' => (float) $rows->sum('total_cost_variance')]]);
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
        $orders = $this->companyScope(ProductionOrder::with(['product:id,name,sku', 'bom:id,code,version', 'location:id,code,name', 'operations.workCenter', 'operations.routingOperation']), $request->user()?->company_id)
            ->whereIn('status', ['released', 'in_progress'])
            ->when($data['planned_from'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '>=', $date))
            ->when($data['planned_to'] ?? null, fn ($query, $date) => $query->whereDate('planned_date', '<=', $date))
            ->orderBy('planned_date')->orderBy('id')->get();
        $orderIds = $orders->pluck('id');
        $movements = InventoryMovement::where('company_id', $request->user()?->company_id)
            ->where('reference_type', (new ProductionOrder())->getMorphClass())->whereIn('reference_id', $orderIds)->where('movement_type', 'issue')->get();
        $consumption = InventoryCostConsumption::whereIn('movement_id', $movements->pluck('id'))->selectRaw('movement_id, SUM(total_cost) AS total_cost')->groupBy('movement_id')->pluck('total_cost', 'movement_id');
        $materialCosts = $movements->groupBy('reference_id')->map(fn ($rows): float => (float) $rows->sum(fn (InventoryMovement $movement): float => $consumption->has($movement->id) ? (float) $consumption->get($movement->id) : (float) $movement->quantity * (float) ($movement->unit_cost ?? 0)));
        $orders = $orders->map(function (ProductionOrder $order) use ($materialCosts): array {
                $planned = (float) $order->planned_quantity;
                $completed = (float) $order->completed_quantity;
                $operations = $order->operations->map(function (ProductionOperation $operation): array {
                    $routing = $operation->routingOperation;
                    $remaining = max(0, (float) $operation->planned_quantity - (float) $operation->completed_quantity);
                    $rate = (float) ($operation->workCenter?->labor_rate ?? 0) + (float) ($operation->workCenter?->machine_rate ?? 0);
                    $setup = in_array($operation->status, ['completed', 'skipped', 'cancelled'], true) ? 0 : (float) ($routing?->setup_minutes ?? 0);
                    $run = $remaining * (float) ($routing?->run_minutes ?? 0);
                    $remainingCost = (($setup + $run) / 60) * $rate;
                    $actualCost = (((float) ($operation->actual_setup_minutes ?? 0) + (float) ($operation->actual_run_minutes ?? 0)) / 60) * $rate;
                    return ['id' => $operation->id, 'sequence' => (int) $operation->sequence, 'operation' => $operation->operation, 'status' => $operation->status, 'schedule_status' => $operation->schedule_status, 'scheduled_start_at' => optional($operation->scheduled_start_at)->toISOString(), 'scheduled_end_at' => optional($operation->scheduled_end_at)->toISOString(), 'work_center' => $operation->workCenter, 'planned_quantity' => (float) $operation->planned_quantity, 'completed_quantity' => (float) $operation->completed_quantity, 'remaining_quantity' => $remaining, 'estimated_remaining_cost' => round($remainingCost, 6), 'actual_cost' => round($actualCost, 6)];
                })->values();
                $materialCost = $materialCosts->has($order->id) ? (float) $materialCosts->get($order->id) : (float) $order->material_cost;
                $materialCostSource = $materialCosts->has($order->id) ? 'cost_layers' : 'production_order';
                $actualOperationCost = (float) $operations->sum('actual_cost');
                return ['id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status, 'planned_date' => optional($order->planned_date)->toDateString(), 'product' => $order->product, 'bom' => $order->bom, 'location' => $order->location, 'planned_quantity' => $planned, 'completed_quantity' => $completed, 'remaining_quantity' => max(0, $planned - $completed), 'completion_percent' => $planned > 0 ? min(100, ($completed / $planned) * 100) : 0, 'material_cost' => $materialCost, 'material_cost_source' => $materialCostSource, 'material_cost_to_date' => $materialCost, 'operation_cost' => $actualOperationCost, 'planned_operation_cost' => (float) $order->operation_cost, 'byproduct_cost' => (float) $order->byproduct_cost, 'production_cost' => $materialCost + $actualOperationCost - (float) $order->byproduct_cost, 'wip_value' => $materialCost + $actualOperationCost - (float) $order->byproduct_cost, 'estimated_remaining_operation_cost' => (float) $operations->sum('estimated_remaining_cost'), 'operations' => $operations];
            })->values();
        return response()->json(['data' => $orders, 'totals' => ['order_count' => $orders->count(), 'planned_quantity' => (float) $orders->sum('planned_quantity'), 'completed_quantity' => (float) $orders->sum('completed_quantity'), 'remaining_quantity' => (float) $orders->sum('remaining_quantity'), 'production_cost' => (float) $orders->sum('production_cost'), 'estimated_remaining_operation_cost' => (float) $orders->sum('estimated_remaining_operation_cost')]]);
    }

    public function boms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'], 'version' => ['nullable', 'string', 'max:50'], 'as_of' => ['nullable', 'date'], 'is_active' => ['nullable', 'boolean'], 'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $boms = $this->companyScope(BillOfMaterial::with(['product:id,name,sku', 'lines.component:id,name,sku', 'lines.uom:id,name,code', 'byproducts.product:id,name,sku']), $request->user()?->company_id)
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['version'] ?? null, fn ($query, $version) => $query->where('version', $version))
            ->when($data['as_of'] ?? null, function ($query, $date): void {
                $query->where(fn ($scope) => $scope->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                    ->where(fn ($scope) => $scope->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
            })
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['approval_status'] ?? null, fn ($query, $status) => $query->where('approval_status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($boms, $request, 'manufacturing.boms', (int) ($data['per_page'] ?? 50));
    }

    public function storeBom(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
            'product_id' => ['required', 'integer', $owned('products')], 'code' => ['required', 'string', 'max:50'],
            'version' => ['nullable', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'output_quantity' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.component_product_id' => ['required', 'integer', $owned('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.uom_id' => ['nullable', 'integer', $owned('units')], 'lines.*.scrap_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'byproducts' => ['nullable', 'array'], 'byproducts.*.product_id' => ['required', 'integer', $owned('products')],
            'byproducts.*.quantity' => ['required', 'numeric', 'gt:0'], 'byproducts.*.cost_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(BillOfMaterial::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['product', 'lines.component', 'byproducts.product']), 'status' => 'duplicate_ignored']);
        }
        $componentIds = array_map(fn (array $line): int => (int) $line['component_product_id'], $data['lines']);
        if (in_array((int) $data['product_id'], $componentIds, true) || count($componentIds) !== count(array_unique($componentIds))) abort(422, 'BOM components must be distinct and cannot include the finished product.');
        $this->validateManufacturingUoms($data['lines'], $companyId);
        $shares = array_sum(array_map(fn (array $line): float => (float) ($line['cost_share_percent'] ?? 0), $data['byproducts'] ?? []));
        if ($shares > 100.000001) abort(422, 'By-product cost shares cannot exceed 100%.');
        if ($data['is_active'] ?? true) app(BomRevisionService::class)->assertNoActiveOverlap((int) $data['product_id'], $data['effective_from'] ?? null, $data['effective_until'] ?? null, $companyId);
        $bom = DB::transaction(function () use ($data, $companyId, $request): BillOfMaterial {
            $bom = BillOfMaterial::create(['company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'product_id' => $data['product_id'], 'code' => $data['code'], 'version' => $data['version'] ?? '1', 'name' => $data['name'], 'output_quantity' => $data['output_quantity'], 'effective_from' => $data['effective_from'] ?? null, 'effective_until' => $data['effective_until'] ?? null, 'is_active' => $data['is_active'] ?? true, 'approval_status' => 'pending', 'created_by' => $request->user()?->id]);
            foreach ($data['lines'] as $line) BomLine::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'component_product_id' => $line['component_product_id'], 'uom_id' => $line['uom_id'] ?? null, 'quantity' => $line['quantity'], 'scrap_percent' => $line['scrap_percent'] ?? 0]);
            foreach ($data['byproducts'] ?? [] as $line) BomByproduct::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'cost_share_percent' => $line['cost_share_percent'] ?? 0]);
            app(AuditService::class)->record('bom.created', $bom, null, $bom->toArray() + ['api' => true, 'created_by' => $request->user()?->id]);
            return $bom;
        });
        return response()->json(['data' => $bom->load(['product', 'lines.component', 'byproducts.product']), 'status' => 'pending_approval'], 201);
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
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'], 'lines.*.uom_id' => ['nullable', 'integer', $owned('units')], 'lines.*.scrap_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'byproducts' => ['sometimes', 'array'], 'byproducts.*.product_id' => ['required', 'integer', $owned('products')],
            'byproducts.*.quantity' => ['required', 'numeric', 'gt:0'], 'byproducts.*.cost_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (array_key_exists('effective_from', $data) && array_key_exists('effective_until', $data) && $data['effective_from'] && $data['effective_until'] && $data['effective_until'] < $data['effective_from']) abort(422, 'The effective end date must not precede the start date.');
        $productId = (int) ($data['product_id'] ?? $bom->product_id);
        if (array_key_exists('lines', $data)) {
            $componentIds = array_map(fn (array $line): int => (int) $line['component_product_id'], $data['lines']);
            if (in_array($productId, $componentIds, true) || count($componentIds) !== count(array_unique($componentIds))) abort(422, 'BOM components must be distinct and cannot include the finished product.');
            $this->validateManufacturingUoms($data['lines'], $companyId);
        }
        if (array_key_exists('byproducts', $data) && array_sum(array_map(fn (array $line): float => (float) ($line['cost_share_percent'] ?? 0), $data['byproducts'])) > 100.000001) abort(422, 'By-product cost shares cannot exceed 100%.');
        if ($data['is_active'] ?? $bom->is_active) app(BomRevisionService::class)->assertNoActiveOverlap($productId, $data['effective_from'] ?? $bom->effective_from?->toDateString(), $data['effective_until'] ?? $bom->effective_until?->toDateString(), $companyId, $bom->id);
        $updated = DB::transaction(function () use ($data, $bom, $productId, $companyId): BillOfMaterial {
            $bom->update(array_filter(['external_reference' => $data['external_reference'] ?? $bom->external_reference, 'product_id' => $productId, 'code' => $data['code'] ?? $bom->code, 'version' => $data['version'] ?? ($bom->version ?: '1'), 'name' => $data['name'] ?? $bom->name, 'output_quantity' => $data['output_quantity'] ?? $bom->output_quantity, 'effective_from' => $data['effective_from'] ?? $bom->effective_from, 'effective_until' => $data['effective_until'] ?? $bom->effective_until, 'is_active' => $data['is_active'] ?? $bom->is_active], fn ($value): bool => $value !== null) + (($bom->approval_status ?? 'approved') === 'approved' ? ['approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null] : []));
            if (array_key_exists('lines', $data)) {
                $bom->lines()->delete();
                foreach ($data['lines'] as $line) BomLine::create(['company_id' => $companyId, 'bom_id' => $bom->id, 'component_product_id' => $line['component_product_id'], 'uom_id' => $line['uom_id'] ?? null, 'quantity' => $line['quantity'], 'scrap_percent' => $line['scrap_percent'] ?? 0]);
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

    public function approveBom(int $id): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $bom = $this->companyScope(BillOfMaterial::query(), $companyId)->with('product')->findOrFail($id);
        if (in_array($bom->approval_status ?? 'approved', ['approved'], true)) return response()->json(['data' => $bom, 'status' => 'already_approved']);
        if ($bom->created_by && (int) $bom->created_by === (int) auth()->id()) abort(422, 'The BOM creator cannot approve the same revision.');
        if ($bom->is_active) app(BomRevisionService::class)->assertNoActiveOverlap($bom->product_id, $bom->effective_from?->toDateString(), $bom->effective_until?->toDateString(), $companyId, $bom->id);
        $before = $bom->only(['approval_status', 'approved_by', 'approved_at', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $bom->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]);
        app(AuditService::class)->record('bom.approved', $bom, $before, $bom->fresh()->only(['approval_status', 'approved_by', 'approved_at']));
        return response()->json(['data' => $bom->fresh(), 'status' => 'approved']);
    }

    public function rejectBom(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $bom = $this->companyScope(BillOfMaterial::query(), auth()->user()?->company_id)->findOrFail($id);
        if (($bom->approval_status ?? 'approved') === 'approved') abort(422, 'An approved BOM must be replaced with a new revision instead of rejected.');
        $before = $bom->only(['approval_status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $bom->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('bom.rejected', $bom, $before, $bom->fresh()->only(['approval_status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return response()->json(['data' => $bom->fresh(), 'status' => 'rejected']);
    }

    public function compareBoms(int $id, int $otherId): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $with = ['product:id,name,sku', 'lines.component:id,name,sku', 'lines.uom:id,name,code', 'byproducts.product:id,name,sku'];
        $left = $this->companyScope(BillOfMaterial::with($with), $companyId)->findOrFail($id);
        $right = $this->companyScope(BillOfMaterial::with($with), $companyId)->findOrFail($otherId);
        if ((int) $left->product_id !== (int) $right->product_id) abort(422, 'BOM revisions can only be compared for the same finished product.');
        return response()->json(['data' => [
            'from' => $this->bomRevisionSnapshot($left),
            'to' => $this->bomRevisionSnapshot($right),
            'header_changes' => $this->bomHeaderChanges($left, $right),
            'component_changes' => $this->bomLineChanges($left->lines, $right->lines, 'component_product_id'),
            'byproduct_changes' => $this->bomLineChanges($left->byproducts, $right->byproducts, 'product_id'),
        ]]);
    }

    private function bomRevisionSnapshot(BillOfMaterial $bom): array
    {
        return ['id' => $bom->id, 'code' => $bom->code, 'version' => $bom->version, 'name' => $bom->name, 'output_quantity' => (float) $bom->output_quantity, 'approval_status' => $bom->approval_status ?? 'approved', 'is_active' => (bool) $bom->is_active, 'effective_from' => optional($bom->effective_from)->toDateString(), 'effective_until' => optional($bom->effective_until)->toDateString()];
    }

    private function bomHeaderChanges(BillOfMaterial $left, BillOfMaterial $right): array
    {
        $fields = ['code', 'version', 'name', 'output_quantity', 'approval_status', 'is_active', 'effective_from', 'effective_until'];
        $changes = [];
        foreach ($fields as $field) {
            $from = in_array($field, ['output_quantity', 'is_active'], true) ? ($field === 'output_quantity' ? (float) $left->{$field} : (bool) $left->{$field}) : ($field === 'approval_status' ? ($left->{$field} ?? 'approved') : ($left->{$field} instanceof Carbon ? $left->{$field}->toDateString() : $left->{$field}));
            $to = in_array($field, ['output_quantity', 'is_active'], true) ? ($field === 'output_quantity' ? (float) $right->{$field} : (bool) $right->{$field}) : ($field === 'approval_status' ? ($right->{$field} ?? 'approved') : ($right->{$field} instanceof Carbon ? $right->{$field}->toDateString() : $right->{$field}));
            if ($from !== $to) $changes[$field] = ['from' => $from, 'to' => $to];
        }
        return $changes;
    }

    private function bomLineChanges($fromLines, $toLines, string $productKey): array
    {
        $key = fn ($line): string => (string) $line->{$productKey};
        $from = $fromLines->keyBy($key); $to = $toLines->keyBy($key); $changes = [];
        foreach ($from->keys()->diff($to->keys()) as $lineKey) $changes[] = ['type' => 'removed', 'product_id' => (int) $lineKey, 'from' => $from[$lineKey]->toArray(), 'to' => null];
        foreach ($to->keys()->diff($from->keys()) as $lineKey) $changes[] = ['type' => 'added', 'product_id' => (int) $lineKey, 'from' => null, 'to' => $to[$lineKey]->toArray()];
        foreach ($from->keys()->intersect($to->keys()) as $lineKey) {
            $old = $from[$lineKey]; $new = $to[$lineKey];
            $fields = $productKey === 'component_product_id' ? ['quantity', 'uom_id', 'scrap_percent'] : ['quantity', 'cost_share_percent'];
            $fieldChanges = [];
            foreach ($fields as $field) if ((string) $old->{$field} !== (string) $new->{$field}) $fieldChanges[$field] = ['from' => $old->{$field}, 'to' => $new->{$field}];
            if ($fieldChanges) $changes[] = ['type' => 'changed', 'product_id' => (int) $lineKey, 'changes' => $fieldChanges];
        }
        return $changes;
    }

    private function validateManufacturingUoms(array $lines, int $companyId): void
    {
        foreach ($lines as $line) {
            if (empty($line['uom_id'])) continue;
            $component = Product::withoutGlobalScope('company')->whereKey($line['component_product_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
            try { app(UomConversionService::class)->toStock($component, (float) $line['quantity'], (int) $line['uom_id'], 'manufacturing'); }
            catch (\InvalidArgumentException $exception) { abort(422, $exception->getMessage()); }
        }
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
