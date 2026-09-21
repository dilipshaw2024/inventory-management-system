<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReplenishmentPolicyController extends Controller
{
    public function index()
    {
        $policies = $this->ownedPolicies()->with(['product', 'location'])->latest()->paginate(40);
        return view('backend.stock.replenishment_policies', compact('policies'));
    }

    public function create()
    {
        return view('backend.stock.replenishment_policy_add', ['products' => Product::where('status', 1)->orderBy('name')->get(), 'locations' => InventoryLocation::where('is_active', true)->orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['product_id' => ['required', 'integer', $owned('products')], 'location_id' => ['required', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)], 'reorder_point' => ['required', 'numeric', 'min:0'], 'reorder_point_method' => ['nullable', 'in:fixed,demand'], 'reorder_history_days' => ['nullable', 'integer', 'min:7', 'max:730'], 'safety_stock' => ['nullable', 'numeric', 'min:0'], 'safety_stock_method' => ['nullable', 'in:fixed,variability'], 'service_level_z' => ['nullable', 'numeric', 'gt:0', 'max:6'], 'min_stock' => ['nullable', 'numeric', 'min:0'], 'max_stock' => ['nullable', 'numeric', 'gte:min_stock'], 'lead_time_days' => ['nullable', 'integer', 'min:0'], 'safety_time_days' => ['nullable', 'integer', 'min:0']]);
        $locationOwned = InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists();
        if (!$locationOwned) return back()->withInput()->withErrors(['location_id' => 'The selected location is not part of your company.']);
        $policy = InventoryReplenishmentPolicy::updateOrCreate(['product_id' => $data['product_id'], 'location_id' => $data['location_id']], $data + ['is_active' => true]);
        app(AuditService::class)->record('inventory_replenishment_policy.saved', $policy, null, $policy->toArray());
        return redirect()->route('planning.policies.index')->with(['message' => 'Location replenishment policy saved.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $policy = $this->ownedPolicies()->with(['product', 'location'])->findOrFail($id);
        $data = $this->validatedPolicy($request);
        $before = $policy->toArray();
        $policy->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('inventory_replenishment_policy.updated', $policy, $before, $policy->fresh()->toArray());
        return back()->with(['message' => 'Location replenishment policy updated.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $policy = $this->ownedPolicies()->findOrFail($id);
        if ($policy->is_active) {
            $policy->update(['is_active' => false]);
            app(AuditService::class)->record('inventory_replenishment_policy.deactivated', $policy, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Location replenishment policy deactivated.', 'alert-type' => 'success']);
    }

    private function validatedPolicy(Request $request): array
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['product_id' => ['required', 'integer', $owned('products')], 'location_id' => ['required', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)], 'reorder_point' => ['required', 'numeric', 'min:0'], 'reorder_point_method' => ['nullable', 'in:fixed,demand'], 'reorder_history_days' => ['nullable', 'integer', 'min:7', 'max:730'], 'safety_stock' => ['nullable', 'numeric', 'min:0'], 'safety_stock_method' => ['nullable', 'in:fixed,variability'], 'service_level_z' => ['nullable', 'numeric', 'gt:0', 'max:6'], 'min_stock' => ['nullable', 'numeric', 'min:0'], 'max_stock' => ['nullable', 'numeric', 'gte:min_stock'], 'lead_time_days' => ['nullable', 'integer', 'min:0'], 'safety_time_days' => ['nullable', 'integer', 'min:0']]);
        $locationOwned = InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists();
        if (!$locationOwned) abort(422, 'The selected location is not part of your company.');
        return $data;
    }

    private function ownedPolicies()
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for replenishment policies.');
        return InventoryReplenishmentPolicy::whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId));
    }
}
