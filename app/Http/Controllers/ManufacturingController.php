<?php

namespace App\Http\Controllers;

use App\Models\BillOfMaterial;
use App\Models\BomLine;
use App\Models\BomByproduct;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Routing;
use App\Models\RoutingOperation;
use App\Models\WorkCenter;
use App\Models\InventoryLocation;
use App\Services\AuditService;
use App\Services\ProductionService;
use App\Services\NumberingSequenceService;
use App\Services\ProductionOperationService;
use App\Services\BomRevisionService;
use App\Services\ProductionSchedulingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ManufacturingController extends Controller
{
    private function companyExists(string $table)
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'));
    }

    private function locationExists()
    {
        return \App\Services\InventoryLocationRuleService::existsForCompany(auth()->user()?->company_id);
    }

    public function routing()
    {
        $routings = Routing::with(['bom.product', 'operations.workCenter'])->latest()->paginate(25);
        $boms = BillOfMaterial::with('product')->where('is_active', true)->orderBy('name')->get();
        $workCenters = WorkCenter::where('is_active', true)->orderBy('name')->get();
        return view('admin.erp.routings', compact('routings', 'boms', 'workCenters'));
    }

    public function storeWorkCenter(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['code' => ['required', 'string', 'max:50', Rule::unique('work_centers', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:255'], 'capacity_hours_per_day' => ['required', 'numeric', 'gt:0'], 'labor_rate' => ['nullable', 'numeric', 'min:0'], 'machine_rate' => ['nullable', 'numeric', 'min:0'], 'shift_start' => ['nullable', 'date_format:H:i'], 'shift_end' => ['nullable', 'date_format:H:i']]);
        if (!empty($data['shift_start']) && !empty($data['shift_end']) && $data['shift_end'] <= $data['shift_start']) return back()->withErrors(['shift_end' => 'Shift end must be after shift start.'])->withInput();
        $calendar = array_filter(['shift_start' => $data['shift_start'] ?? null, 'shift_end' => $data['shift_end'] ?? null]);
        unset($data['shift_start'], $data['shift_end']);
        $center = WorkCenter::create($data + ['calendar' => $calendar ?: null, 'company_id' => $companyId]);
        app(AuditService::class)->record('work_center.created', $center, null, $center->toArray());
        return back()->with(['message' => 'Work center created.', 'alert-type' => 'success']);
    }

    public function storeRouting(Request $request)
    {
        $data = $request->validate(['bom_id' => ['required', $this->companyExists('bills_of_materials')], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'work_center_id' => ['required', 'array', 'min:1'], 'work_center_id.*' => ['required', $this->companyExists('work_centers')], 'operation' => ['required', 'array'], 'operation.*' => ['required', 'string', 'max:255'], 'setup_minutes' => ['nullable', 'array'], 'setup_minutes.*' => ['nullable', 'numeric', 'min:0'], 'run_minutes' => ['nullable', 'array'], 'run_minutes.*' => ['nullable', 'numeric', 'min:0']]);
        $routing = Routing::create(collect($data)->except(['work_center_id', 'operation', 'setup_minutes', 'run_minutes'])->all() + ['company_id' => auth()->user()?->company_id]);
        foreach ($data['work_center_id'] as $index => $workCenterId) RoutingOperation::create(['company_id' => auth()->user()?->company_id, 'routing_id' => $routing->id, 'work_center_id' => $workCenterId, 'sequence' => $index + 1, 'operation' => $data['operation'][$index], 'setup_minutes' => $data['setup_minutes'][$index] ?? 0, 'run_minutes' => $data['run_minutes'][$index] ?? 0]);
        app(AuditService::class)->record('routing.created', $routing, null, $routing->toArray());
        return back()->with(['message' => 'Routing created.', 'alert-type' => 'success']);
    }

    public function boms()
    {
        $boms = BillOfMaterial::with(['product', 'lines.component'])->latest()->paginate(25);
        $products = Product::where('status', 1)->orderBy('name')->get();
        return view('admin.erp.boms', compact('boms', 'products'));
    }

    public function storeBom(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', $this->companyExists('products')],
            'code' => ['required', 'string', 'max:50'],
            'version' => ['nullable', 'string', 'max:50'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'name' => ['required', 'string', 'max:255'],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'component_product_id' => ['required', 'array', 'min:1'],
            'component_product_id.0' => ['required', $this->companyExists('products'), 'different:product_id'],
            'component_product_id.*' => ['nullable', $this->companyExists('products'), 'different:product_id'],
            'quantity' => ['required', 'array'],
            'quantity.0' => ['required', 'numeric', 'gt:0'],
            'quantity.*' => ['nullable', 'numeric', 'gt:0', 'required_with:component_product_id.*'],
            'scrap_percent' => ['nullable', 'array'],
            'scrap_percent.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'byproduct_product_id' => ['nullable', 'array'], 'byproduct_product_id.*' => ['nullable', $this->companyExists('products'), 'different:product_id'],
            'byproduct_quantity' => ['nullable', 'array'], 'byproduct_quantity.*' => ['nullable', 'numeric', 'gt:0'],
            'byproduct_cost_share' => ['nullable', 'array'], 'byproduct_cost_share.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (array_sum(array_map('floatval', $data['byproduct_cost_share'] ?? [])) > 100.000001) {
            return back()->withErrors(['byproduct_cost_share' => 'By-product cost shares cannot exceed 100%.'])->withInput();
        }
        if ($data['is_active'] ?? true) {
            try { app(BomRevisionService::class)->assertNoActiveOverlap((int) $data['product_id'], $data['effective_from'] ?? null, $data['effective_until'] ?? null, auth()->user()?->company_id); }
            catch (\RuntimeException $exception) { return back()->withErrors(['effective_from' => $exception->getMessage()])->withInput(); }
        }
        $bom = BillOfMaterial::create(collect($data)->except(['component_product_id', 'quantity', 'scrap_percent', 'byproduct_product_id', 'byproduct_quantity', 'byproduct_cost_share'])->all() + ['version' => $data['version'] ?? '1', 'company_id' => auth()->user()?->company_id]);
        foreach ($data['component_product_id'] as $index => $componentId) {
            if (!$componentId) continue;
            BomLine::create(['company_id' => auth()->user()?->company_id, 'bom_id' => $bom->id, 'component_product_id' => $componentId, 'quantity' => $data['quantity'][$index], 'scrap_percent' => $data['scrap_percent'][$index] ?? 0]);
        }
        foreach ($data['byproduct_product_id'] ?? [] as $index => $productId) {
            if (!$productId) continue;
            BomByproduct::create(['company_id' => auth()->user()?->company_id, 'bom_id' => $bom->id, 'product_id' => $productId, 'quantity' => $data['byproduct_quantity'][$index], 'cost_share_percent' => $data['byproduct_cost_share'][$index] ?? 0]);
        }
        app(AuditService::class)->record('bom.created', $bom, null, $bom->toArray());
        return back()->with(['message' => 'Bill of material created.', 'alert-type' => 'success']);
    }

    public function orders()
    {
        $orders = ProductionOrder::with(['product', 'bom', 'operations'])->latest()->paginate(25);
        return view('admin.erp.production_orders', compact('orders'));
    }

    public function createOrder(Request $request)
    {
        $boms = BillOfMaterial::with('product')->where('is_active', true)->orderBy('name')->get();
        $selectedBomId = $request->integer('bom_id') ?: null;
        $suggestedQuantity = $request->input('quantity');
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        return view('admin.erp.production_order_create', compact('boms', 'locations', 'selectedBomId', 'suggestedQuantity'));
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate(['bom_id' => ['required', $this->companyExists('bills_of_materials')], 'location_id' => ['nullable', 'integer', $this->locationExists()], 'planned_quantity' => ['required', 'numeric', 'gt:0'], 'planned_date' => ['required', 'date'], 'description' => ['nullable', 'string'], 'output_batch_no' => ['nullable', 'string', 'max:100'], 'output_serial_numbers' => ['nullable', 'string', 'max:10000'], 'output_manufacturing_date' => ['nullable', 'date'], 'output_expiry_date' => ['nullable', 'date'], 'output_best_before_date' => ['nullable', 'date'], 'output_warranty_until' => ['nullable', 'date']]);
        $bom = BillOfMaterial::findOrFail($data['bom_id']);
        $plannedDate = Carbon::parse($data['planned_date'])->toDateString();
        if (!$bom->is_active || ($bom->effective_from && $bom->effective_from->gt($plannedDate)) || ($bom->effective_until && $bom->effective_until->lt($plannedDate))) return back()->withErrors(['bom_id' => 'The selected BOM is inactive or not effective on the planned production date.'])->withInput();
        $bomSnapshot = app(\App\Services\BomExplosionService::class)->snapshot($bom, auth()->user()?->company_id, $plannedDate);
        $order = ProductionOrder::create($data + ['company_id' => auth()->user()?->company_id, 'product_id' => $bom->product_id, 'bom_version' => $bom->version ?: '1', 'bom_snapshot' => $bomSnapshot, 'order_no' => app(NumberingSequenceService::class)->nextOrFallback('production_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'created_by' => auth()->id()]);
        app(AuditService::class)->record('production_order.created', $order, null, $order->toArray());
        return redirect()->route('manufacturing.orders')->with(['message' => 'Production order created.', 'alert-type' => 'success']);
    }

    public function release(int $id)
    {
        try { app(ProductionService::class)->release($id); return back()->with(['message' => 'Production order released.', 'alert-type' => 'success']); }
        catch (\Throwable $e) { return back()->withErrors(['production' => $e->getMessage()]); }
    }

    public function schedule(int $id)
    {
        try {
            app(ProductionSchedulingService::class)->schedule(ProductionOrder::findOrFail($id));
            return back()->with(['message' => 'Production operations scheduled against work-center capacity.', 'alert-type' => 'success']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()]);
        }
    }

    public function complete(Request $request, int $id)
    {
        $data = $request->validate(['produced_quantity' => ['nullable', 'numeric', 'gt:0']]);
        try { $order = app(ProductionService::class)->complete($id, isset($data['produced_quantity']) ? (float) $data['produced_quantity'] : null); return back()->with(['message' => $order->status === 'completed' ? 'Production order completed and stock posted.' : 'Partial production received and order remains in progress.', 'alert-type' => 'success']); }
        catch (\Throwable $e) { return back()->withErrors(['production' => $e->getMessage()]); }
    }

    public function cancel(Request $request, int $id)
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        try { app(ProductionService::class)->cancel($id, $data['cancellation_reason']); }
        catch (\Throwable $e) { return back()->withErrors(['production' => $e->getMessage()]); }
        return back()->with(['message' => 'Production order cancelled and component reservations released.', 'alert-type' => 'success']);
    }

    public function operations(int $id)
    {
        $order = ProductionOrder::with(['product', 'operations.workCenter'])->findOrFail($id);
        return view('admin.erp.production_operations', compact('order'));
    }

    public function startOperation(int $id)
    {
        try { app(ProductionOperationService::class)->start($id); return back()->with(['message' => 'Production operation started.', 'alert-type' => 'success']); }
        catch (\Throwable $exception) { return back()->withErrors(['operation' => $exception->getMessage()]); }
    }

    public function completeOperation(Request $request, int $id)
    {
        $data = $request->validate(['completed_quantity' => ['required', 'numeric', 'gt:0'], 'actual_setup_minutes' => ['nullable', 'numeric', 'min:0'], 'actual_run_minutes' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try { app(ProductionOperationService::class)->complete($id, (float) $data['completed_quantity'], isset($data['actual_setup_minutes']) ? (float) $data['actual_setup_minutes'] : null, isset($data['actual_run_minutes']) ? (float) $data['actual_run_minutes'] : null, $data['notes'] ?? null); return back()->with(['message' => 'Production operation completed.', 'alert-type' => 'success']); }
        catch (\Throwable $exception) { return back()->withErrors(['operation' => $exception->getMessage()]); }
    }
}
