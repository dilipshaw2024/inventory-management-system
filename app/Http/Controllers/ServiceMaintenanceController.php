<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MaintenanceOrder;
use App\Models\Product;
use App\Models\ServiceAsset;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\MaintenancePart;
use App\Models\ServiceTechnician;
use App\Models\ServiceMaintenanceSchedule;
use App\Models\WarrantyClaim;
use App\Models\AssetSparePart;
use App\Models\ServiceContract;
use App\Services\InventoryLedgerService;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use App\Services\ProductLifecycleService;
use App\Services\MaintenancePartConsumptionService;
use App\Services\MaintenancePartReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ServiceMaintenanceController extends Controller
{
    private function companyExists(string $table)
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'));
    }

    private function companyUnique(string $table, string $column)
    {
        $companyId = auth()->user()?->company_id;
        return Rule::unique($table, $column)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    public function index()
    {
        $technicians = ServiceTechnician::with('user')->latest()->get();
        $schedules = ServiceMaintenanceSchedule::with(['asset', 'assignee'])->orderBy('next_due')->get();
        $users = User::where('is_active', true)->orderBy('name')->get();
        $assets = ServiceAsset::with(['product', 'customer'])->latest()->paginate(25, ['*'], 'assets');
        $requests = ServiceRequest::with(['asset', 'customer', 'assignee'])->latest()->paginate(25, ['*'], 'requests');
        $orders = MaintenanceOrder::with(['asset', 'assignee'])->latest()->paginate(25, ['*'], 'orders');
        return view('admin.erp.service_maintenance', compact('assets', 'requests', 'orders', 'technicians', 'schedules'));
    }

    public function create()
    {
        $products = Product::orderBy('name')->get(); $customers = Customer::orderBy('name')->get(); $assets = ServiceAsset::where('status', '!=', 'retired')->orderBy('name')->get(); $technicians = ServiceTechnician::with('user')->where('is_available', true)->latest()->get(); $contracts = ServiceContract::where('status', 'active')->whereDate('starts_on', '<=', now())->whereDate('ends_on', '>=', now())->with(['customer', 'asset'])->orderBy('ends_on')->get();
        return view('admin.erp.service_maintenance_create', compact('products', 'customers', 'assets', 'technicians', 'contracts'));
    }

    public function warrantyClaims()
    {
        $claims = WarrantyClaim::with(['asset', 'customer', 'product'])->latest()->paginate(25);
        $assets = ServiceAsset::where('status', '!=', 'retired')->orderBy('name')->get();
        return view('admin.erp.warranty_claims', compact('claims', 'assets'));
    }

    public function warrantyClaim(Request $request)
    {
        $data = $request->validate(['claim_no' => ['nullable', 'string', 'max:80', $this->companyUnique('warranty_claims', 'claim_no')], 'asset_id' => ['required', $this->companyExists('service_assets')], 'received_at' => ['required', 'date'], 'issue' => ['required', 'string', 'max:3000']]);
        $asset = ServiceAsset::with('product')->findOrFail($data['asset_id']);
        $data['coverage_status'] = !$asset->warranty_until ? 'unknown' : ($asset->warranty_until->isBefore($data['received_at']) ? 'expired' : 'in_warranty');
        if ($data['coverage_status'] === 'expired') $data['decision_notes'] = 'Submitted after recorded warranty expiry; review required.';
        $claim = WarrantyClaim::create($data + ['company_id' => auth()->user()?->company_id, 'claim_no' => $data['claim_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('warranty_claim', 'WC-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'customer_id' => $asset->customer_id, 'product_id' => $asset->product_id, 'created_by' => auth()->id()]);
        app(AuditService::class)->record('warranty_claim.created', $claim, null, $claim->toArray());
        return back()->with(['message' => 'Warranty claim submitted.', 'alert-type' => 'success']);
    }

    public function spareParts(int $id)
    {
        $asset = ServiceAsset::with(['spareParts.product'])->findOrFail($id);
        $products = Product::where('status', 1)->where(function ($query): void { $query->where('is_stock_item', true)->orWhereNull('is_stock_item'); })->orderBy('name')->get();
        return view('admin.erp.asset_spare_parts', compact('asset', 'products'));
    }

    public function storeSparePart(Request $request, int $id)
    {
        $data = $request->validate(['product_id' => ['required', $this->companyExists('products')], 'quantity_per_service' => ['required', 'numeric', 'gt:0'], 'minimum_stock' => ['nullable', 'numeric', 'min:0'], 'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock'], 'notes' => ['nullable', 'string', 'max:255']]);
        $asset = ServiceAsset::findOrFail($id);
        app(ProductLifecycleService::class)->assertStockManaged(Product::findOrFail($data['product_id']));
        $part = AssetSparePart::updateOrCreate(['asset_id' => $asset->id, 'product_id' => $data['product_id']], $data + ['company_id' => auth()->user()?->company_id]);
        app(AuditService::class)->record('asset_spare_part.updated', $part, null, $part->toArray());
        return back()->with(['message' => 'Asset spare part saved.', 'alert-type' => 'success']);
    }

    public function asset(Request $request)
    {
        $data = $request->validate(['asset_no' => ['required', 'string', 'max:80', $this->companyUnique('service_assets', 'asset_no')], 'name' => ['required', 'string', 'max:255'], 'product_id' => ['nullable', $this->companyExists('products')], 'customer_id' => ['nullable', $this->companyExists('customers')], 'serial_no' => ['nullable', 'string', 'max:100'], 'location' => ['nullable', 'string', 'max:500'], 'warranty_until' => ['nullable', 'date'], 'acquisition_cost' => ['nullable', 'numeric', 'min:0'], 'salvage_value' => ['nullable', 'numeric', 'min:0'], 'useful_life_months' => ['nullable', 'integer', 'min:1'], 'depreciation_method' => ['nullable', 'in:straight_line,declining_balance,units_of_production'], 'depreciation_units_total' => ['nullable', 'numeric', 'gt:0'], 'depreciation_units_used' => ['nullable', 'numeric', 'min:0']]);
        if ((float) ($data['salvage_value'] ?? 0) > (float) ($data['acquisition_cost'] ?? 0)) return back()->withInput()->with(['message' => 'Salvage value cannot exceed acquisition cost.', 'alert-type' => 'error']);
        if (isset($data['depreciation_units_total'], $data['depreciation_units_used']) && (float) $data['depreciation_units_used'] > (float) $data['depreciation_units_total']) return back()->withInput()->with(['message' => 'Used depreciation units cannot exceed total units.', 'alert-type' => 'error']);
        $asset = ServiceAsset::create($data + ['company_id' => auth()->user()?->company_id]); app(AuditService::class)->record('service_asset.created', $asset, null, $asset->toArray()); return back()->with(['message' => 'Service asset created.', 'alert-type' => 'success']);
    }

    public function request(Request $request)
    {
        $data = $request->validate(['request_no' => ['required', 'string', 'max:80', $this->companyUnique('service_requests', 'request_no')], 'asset_id' => ['nullable', $this->companyExists('service_assets')], 'customer_id' => ['nullable', $this->companyExists('customers')], 'contract_id' => ['nullable', $this->companyExists('service_contracts')], 'priority' => ['required', 'in:low,normal,high,urgent'], 'description' => ['required', 'string', 'max:3000']]);
        if (!empty($data['asset_id'])) {
            $asset = ServiceAsset::findOrFail($data['asset_id']);
            if (!empty($data['customer_id']) && $asset->customer_id && (int) $asset->customer_id !== (int) $data['customer_id']) return back()->withInput()->with(['message' => 'The selected asset belongs to a different customer.', 'alert-type' => 'error']);
        }
        if (!empty($data['customer_id'])) Customer::findOrFail($data['customer_id']);
        if (!empty($data['contract_id'])) {
            $contract = ServiceContract::findOrFail($data['contract_id']);
            if ($contract->status !== 'active' || $contract->starts_on->isFuture() || $contract->ends_on->isPast()) return back()->withInput()->with(['message' => 'The selected service contract is not active today.', 'alert-type' => 'error']);
            if ($contract->customer_id && !empty($data['customer_id']) && (int) $contract->customer_id !== (int) $data['customer_id']) return back()->withInput()->with(['message' => 'The service contract belongs to a different customer.', 'alert-type' => 'error']);
            if ($contract->asset_id && !empty($data['asset_id']) && (int) $contract->asset_id !== (int) $data['asset_id']) return back()->withInput()->with(['message' => 'The service contract belongs to a different asset.', 'alert-type' => 'error']);
            $data['customer_id'] = $data['customer_id'] ?? $contract->customer_id;
            $data['asset_id'] = $data['asset_id'] ?? $contract->asset_id;
            $data['response_due_at'] = $contract->response_hours ? now()->addHours((int) $contract->response_hours) : null;
        }
        $serviceRequest = ServiceRequest::create($data + ['company_id' => auth()->user()?->company_id, 'created_by' => auth()->id()]); app(AuditService::class)->record('service_request.created', $serviceRequest, null, $serviceRequest->toArray()); return back()->with(['message' => 'Service request created.', 'alert-type' => 'success']);
    }

    public function assignRequest(Request $request, int $id)
    {
        $data = $request->validate(['technician_id' => ['required', 'integer', $this->companyExists('service_technicians')]]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $serviceRequest = ServiceRequest::lockForUpdate()->findOrFail($id);
                if (!in_array($serviceRequest->status, ['open', 'assigned'], true)) throw new \RuntimeException('Only open or assigned service requests can be assigned.');
                $technician = ServiceTechnician::with('user')->whereKey($data['technician_id'])->lockForUpdate()->firstOrFail();
                if (!$technician->is_available) throw new \RuntimeException('The selected technician is not available.');
                $before = $serviceRequest->only(['status', 'assigned_to', 'assigned_at', 'assigned_by']);
                $serviceRequest->update(['status' => 'assigned', 'assigned_to' => $technician->user_id, 'assigned_at' => now(), 'assigned_by' => auth()->id()]);
                app(AuditService::class)->record('service_request.assigned', $serviceRequest, $before, $serviceRequest->fresh()->only(['status', 'assigned_to', 'assigned_at', 'assigned_by', 'technician_id' => $technician->id]));
            });
            return back()->with(['message' => 'Service request assigned to the technician.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function technician(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', $this->companyExists('users'), 'unique:service_technicians,user_id'], 'employee_code' => ['required', 'string', 'max:50', $this->companyUnique('service_technicians', 'employee_code')], 'skills' => ['nullable', 'string', 'max:1000'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'phone' => ['nullable', 'string', 'max:30']]);
        $technician = ServiceTechnician::create(['company_id' => auth()->user()?->company_id, 'user_id' => $data['user_id'], 'employee_code' => $data['employee_code'], 'skills' => !empty($data['skills']) ? array_values(array_filter(array_map('trim', explode(',', $data['skills'])))) : [], 'hourly_rate' => $data['hourly_rate'] ?? 0, 'phone' => $data['phone'] ?? null, 'is_available' => true]);
        app(AuditService::class)->record('service_technician.created', $technician, null, $technician->toArray());
        return back()->with(['message' => 'Service technician created.', 'alert-type' => 'success']);
    }

    public function schedule(Request $request)
    {
        $data = $request->validate(['asset_id' => ['required', $this->companyExists('service_assets')], 'name' => ['required', 'string', 'max:150'], 'frequency_days' => ['required', 'integer', 'min:1'], 'next_due' => ['required', 'date'], 'assigned_to' => ['nullable', $this->companyExists('users')], 'notes' => ['nullable', 'string', 'max:2000']]);
        $schedule = ServiceMaintenanceSchedule::create($data + ['company_id' => auth()->user()?->company_id, 'is_active' => true, 'created_by' => auth()->id()]);
        app(AuditService::class)->record('maintenance_schedule.created', $schedule, null, $schedule->toArray());
        return back()->with(['message' => 'Preventive maintenance schedule created.', 'alert-type' => 'success']);
    }

    public function generateSchedule(int $id)
    {
        try {
            DB::transaction(function () use ($id): void {
                $schedule = ServiceMaintenanceSchedule::with('asset')->lockForUpdate()->findOrFail($id);
                if (!$schedule->is_active || $schedule->next_due->isFuture()) throw new \RuntimeException('Schedule is inactive or not yet due.');
                $order = MaintenanceOrder::create(['company_id' => $schedule->company_id ?: auth()->user()?->company_id, 'order_no' => app(NumberingSequenceService::class)->nextOrFallback('maintenance_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'asset_id' => $schedule->asset_id, 'maintenance_type' => 'preventive', 'scheduled_date' => $schedule->next_due, 'assigned_to' => $schedule->assigned_to, 'notes' => $schedule->notes, 'created_by' => auth()->id()]);
                $schedule->update(['next_due' => $schedule->next_due->copy()->addDays($schedule->frequency_days), 'last_generated_at' => now()]);
                app(AuditService::class)->record('maintenance_schedule.generated', $schedule, null, ['maintenance_order_id' => $order->id, 'next_due' => $schedule->next_due->toDateString()]);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Preventive maintenance order generated.', 'alert-type' => 'success']);
    }

    public function order(Request $request)
    {
        $data = $request->validate(['order_no' => ['nullable', 'string', 'max:80', $this->companyUnique('maintenance_orders', 'order_no')], 'asset_id' => ['required', $this->companyExists('service_assets')], 'service_request_id' => ['nullable', $this->companyExists('service_requests')], 'maintenance_type' => ['required', 'in:preventive,corrective,inspection'], 'scheduled_date' => ['nullable', 'date'], 'assigned_to' => ['nullable', $this->companyExists('users')], 'notes' => ['nullable', 'string', 'max:3000']]);
        $data['order_no'] = $data['order_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('maintenance_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id);
        try {
            $order = DB::transaction(function () use ($data): MaintenanceOrder {
                $asset = ServiceAsset::findOrFail($data['asset_id']);
                $serviceRequest = !empty($data['service_request_id']) ? ServiceRequest::lockForUpdate()->findOrFail($data['service_request_id']) : null;
                if ($serviceRequest) {
                    if ((int) $serviceRequest->asset_id !== (int) $asset->id) throw new \RuntimeException('The maintenance order asset must match the linked service request asset.');
                    if (in_array($serviceRequest->status, ['resolved', 'cancelled'], true)) throw new \RuntimeException('Resolved or cancelled service requests cannot receive new maintenance orders.');
                }
                if (!empty($data['assigned_to'])) {
                    $technician = ServiceTechnician::where('user_id', $data['assigned_to'])->first();
                    if (!$technician || !$technician->is_available) throw new \RuntimeException('The assigned user must be an available service technician.');
                }
                $order = MaintenanceOrder::create($data + ['company_id' => auth()->user()?->company_id, 'created_by' => auth()->id()]);
                if ($serviceRequest) $serviceRequest->update(['status' => 'in_progress', 'assigned_to' => $data['assigned_to'] ?? $serviceRequest->assigned_to]);
                app(AuditService::class)->record('maintenance_order.created', $order, null, $order->toArray() + ['service_request_id' => $serviceRequest?->id]);
                return $order;
            });
            return back()->with(['message' => 'Maintenance order created.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function parts(int $id)
    {
        $order = MaintenanceOrder::with(['asset', 'parts.product', 'parts.location', 'parts.batch'])->findOrFail($id); $products = Product::where('status', 1)->orderBy('name')->get();
        $locations = \App\Models\InventoryLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        return view('admin.erp.maintenance_parts', compact('order', 'products', 'locations'));
    }

    public function updateOrderStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:in_progress,completed,cancelled'],
            'actual_hours' => ['nullable', 'numeric', 'min:0'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'outcome' => ['nullable', 'string', 'max:3000'],
        ]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $order = MaintenanceOrder::with(['asset', 'serviceRequest'])->lockForUpdate()->findOrFail($id);
                $oldStatus = $order->status;
                if (in_array($oldStatus, ['completed', 'cancelled'], true)) throw new \RuntimeException('A closed maintenance order cannot be changed.');
                if ($data['status'] === 'completed' && !$data['outcome']) throw new \RuntimeException('An outcome is required when completing maintenance.');
                if ($data['status'] === 'in_progress' && $oldStatus !== 'planned') throw new \RuntimeException('Only planned maintenance orders can be started.');
                if ($data['status'] === 'completed' && !in_array($oldStatus, ['planned', 'in_progress'], true)) throw new \RuntimeException('Only planned or in-progress orders can be completed.');
                $updates = ['status' => $data['status'], 'actual_hours' => $data['actual_hours'] ?? $order->actual_hours, 'labor_cost' => $data['labor_cost'] ?? $order->labor_cost, 'outcome' => $data['outcome'] ?? $order->outcome];
                if ($data['status'] === 'in_progress') $updates['started_at'] = $order->started_at ?: now();
                if (in_array($data['status'], ['completed', 'cancelled'], true)) $updates += ['completed_at' => now(), 'completed_by' => auth()->id()];
                $order->update($updates);
                if ($order->asset) $order->asset->update(['status' => $data['status'] === 'in_progress' ? 'under_service' : 'active']);
                if ($data['status'] === 'completed' && $order->serviceRequest) $order->serviceRequest->update(['status' => 'resolved']);
                if ($data['status'] === 'completed') app(\App\Services\MaintenanceLaborAccountingService::class)->post($order);
                app(AuditService::class)->record('maintenance_order.status_updated', $order, ['status' => $oldStatus], $order->only(['status', 'actual_hours', 'labor_cost', 'outcome', 'started_at', 'completed_at', 'completed_by']));
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return back()->with(['message' => 'Maintenance order status updated.', 'alert-type' => 'success']);
    }

    public function consumePart(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['required', $this->companyExists('products')], 'quantity' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'batch_id' => ['nullable', 'integer'], 'serial_numbers' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $order = MaintenanceOrder::findOrFail($id); $product = Product::lockForUpdate()->findOrFail($data['product_id']); $quantity = (float) $data['quantity'];
                app(MaintenancePartConsumptionService::class)->consume($order, $product, $quantity, isset($data['unit_cost']) ? (float) $data['unit_cost'] : null, $data['location_id'] ?? null, $data['batch_id'] ?? null, !empty($data['serial_numbers']) ? preg_split('/[,\\r\\n]+/', $data['serial_numbers']) : []);
                app(AuditService::class)->record('maintenance_part.consumed', $order, null, ['product_id' => $product->id, 'quantity' => $quantity]);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Spare part consumed and stock issued.', 'alert-type' => 'success']);
    }

    public function returnPart(Request $request, int $id, int $partId)
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000'], 'serial_numbers' => ['nullable', 'string', 'max:5000']]);
        try {
            DB::transaction(function () use ($data, $id, $partId): void {
                $order = MaintenanceOrder::findOrFail($id);
                $part = MaintenancePart::where('maintenance_order_id', $order->id)->lockForUpdate()->findOrFail($partId);
                app(MaintenancePartReturnService::class)->returnToStock($order, $part, (float) $data['quantity'], $data['reason'], !empty($data['serial_numbers']) ? preg_split('/[,\\r\\n]+/', $data['serial_numbers']) : []);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Spare part returned to stock.', 'alert-type' => 'success']);
    }
}
