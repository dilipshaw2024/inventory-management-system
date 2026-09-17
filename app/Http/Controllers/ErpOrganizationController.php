<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\InventoryLocation;
use App\Models\Warehouse;
use App\Models\Store;
use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryLocationRule;
use App\Services\AuditService;
use App\Services\InventoryLocationLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ErpOrganizationController extends Controller
{
    public function index()
    {
        $companies = Company::when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereKey($companyId))->with(['branches.warehouses.locations', 'branches.stores', 'departments'])->orderBy('name')->get();
        $rules = InventoryLocationRule::with(['location', 'product', 'category'])->latest()->get();
        $products = Product::where('status', 1)->orderBy('name')->get(['id', 'name', 'sku', 'category_id']);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        return view('admin.erp.organization', compact('companies', 'rules', 'products', 'categories'));
    }

    public function locationRule(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['location_id' => ['required', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)], 'product_id' => ['nullable', 'integer', $owned('products')], 'category_id' => ['nullable', 'integer', $owned('categories')], 'rule_type' => ['required', 'in:allow,deny']]);
        if (empty($data['product_id']) === empty($data['category_id'])) return back()->withInput()->withErrors(['product_id' => 'Select exactly one product or category.']);
        $location = InventoryLocation::findOrFail($data['location_id']);
        if ($companyId && (int) ($location->warehouse?->branch?->company_id ?? 0) !== (int) $companyId) abort(403);
        $rule = InventoryLocationRule::firstOrCreate(['location_id' => $location->id, 'product_id' => $data['product_id'] ?? null, 'category_id' => $data['category_id'] ?? null, 'rule_type' => $data['rule_type']], ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('inventory_location_rule.created', $rule, null, $rule->toArray());
        return back()->with(['message' => $rule->wasRecentlyCreated ? 'Location rule created.' : 'Location rule already exists.', 'alert-type' => 'success']);
    }

    public function deactivateLocationRule(int $id)
    {
        $rule = InventoryLocationRule::whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', auth()->user()?->company_id))->findOrFail($id);
        if ($rule->is_active) { $rule->update(['is_active' => false]); app(AuditService::class)->record('inventory_location_rule.deactivated', $rule, ['is_active' => true], ['is_active' => false]); }
        return back()->with(['message' => 'Location rule deactivated.', 'alert-type' => 'success']);
    }

    public function company(Request $request)
    {
        if (auth()->user()?->company_id) abort(403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:companies,code'],
            'base_currency' => ['required', 'string', 'size:3'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $company = Company::create(array_merge($data, ['base_currency' => strtoupper($data['base_currency'])]));
        app(AuditService::class)->record('company.created', $company, null, $company->toArray());
        return back()->with(['message' => 'Company created.', 'alert-type' => 'success']);
    }

    public function updateCompany(Request $request, int $id)
    {
        $company = Company::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereKey($companyId))
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'base_currency' => ['required', 'string', 'size:3'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (Company::where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This company code already exists.'])->withInput();
        }
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $company->is_active
            && ($company->branches()->where('is_active', true)->exists() || $company->departments()->where('is_active', true)->exists())) {
            return back()->withErrors(['company' => 'Deactivate active branches and departments before deactivating this company.'])->withInput();
        }
        $data['base_currency'] = strtoupper($data['base_currency']);
        $before = $company->toArray();
        $company->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('company.updated', $company, $before, $company->fresh()->toArray());
        return back()->with(['message' => 'Company updated.', 'alert-type' => 'success']);
    }

    public function deactivateCompany(int $id)
    {
        $company = Company::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereKey($companyId))
            ->firstOrFail();
        if ($company->branches()->where('is_active', true)->exists() || $company->departments()->where('is_active', true)->exists()) {
            return back()->withErrors(['company' => 'Deactivate active branches and departments before deactivating this company.']);
        }
        if ($company->is_active) {
            $company->update(['is_active' => false]);
            app(AuditService::class)->record('company.deactivated', $company, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Company deactivated.', 'alert-type' => 'success']);
    }

    public function branch(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        if (auth()->user()?->company_id && (int) $data['company_id'] !== (int) auth()->user()->company_id) abort(403);
        $exists = Branch::where('company_id', $data['company_id'])->where('code', $data['code'])->exists();
        if ($exists) return back()->withErrors(['code' => 'This branch code already exists in the company.'])->withInput();
        $branch = Branch::create($data);
        app(AuditService::class)->record('branch.created', $branch, null, $branch->toArray());
        return back()->with(['message' => 'Branch created.', 'alert-type' => 'success']);
    }

    public function updateBranch(Request $request, int $id)
    {
        $branch = Branch::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (Branch::where('company_id', $branch->company_id)->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This branch code already exists in the company.'])->withInput();
        }
        $before = $branch->toArray();
        $branch->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('branch.updated', $branch, $before, $branch->fresh()->toArray());
        return back()->with(['message' => 'Branch updated.', 'alert-type' => 'success']);
    }

    public function deactivateBranch(int $id)
    {
        $branch = Branch::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->firstOrFail();
        if ($branch->warehouses()->where('is_active', true)->exists() || $branch->stores()->where('is_active', true)->exists()) {
            return back()->withErrors(['branch' => 'Deactivate active warehouses and stores before deactivating this branch.']);
        }
        if ($branch->is_active) {
            $branch->update(['is_active' => false]);
            app(AuditService::class)->record('branch.deactivated', $branch, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Branch deactivated.', 'alert-type' => 'success']);
    }

    public function warehouse(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'warehouse_type' => ['required', 'in:standard,distribution,retail,manufacturing,quarantine,transit'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $branch = Branch::findOrFail($data['branch_id']);
        if (auth()->user()?->company_id && (int) $branch->company_id !== (int) auth()->user()->company_id) abort(403);
        $exists = Warehouse::where('branch_id', $data['branch_id'])->where('code', $data['code'])->exists();
        if ($exists) return back()->withErrors(['code' => 'This warehouse code already exists in the branch.'])->withInput();
        $warehouse = Warehouse::create($data);
        app(AuditService::class)->record('warehouse.created', $warehouse, null, $warehouse->toArray());
        return back()->with(['message' => 'Warehouse created.', 'alert-type' => 'success']);
    }

    public function updateWarehouse(Request $request, int $id)
    {
        $warehouse = Warehouse::with('branch')
            ->whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'warehouse_type' => ['required', 'in:standard,distribution,retail,manufacturing,quarantine,transit'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (Warehouse::where('branch_id', $warehouse->branch_id)->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This warehouse code already exists in the branch.'])->withInput();
        }
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $warehouse->is_active
            && $warehouse->locations()->where('is_active', true)->exists()) {
            return back()->withErrors(['warehouse' => 'Deactivate all active storage locations before deactivating this warehouse.'])->withInput();
        }
        $before = $warehouse->toArray();
        $warehouse->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('warehouse.updated', $warehouse, $before, $warehouse->fresh()->toArray());
        return back()->with(['message' => 'Warehouse updated.', 'alert-type' => 'success']);
    }

    public function deactivateWarehouse(int $id)
    {
        $warehouse = Warehouse::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();
        if ($warehouse->locations()->where('is_active', true)->exists()) {
            return back()->withErrors(['warehouse' => 'Deactivate all active storage locations before deactivating this warehouse.']);
        }
        if ($warehouse->is_active) {
            $warehouse->update(['is_active' => false]);
            app(AuditService::class)->record('warehouse.deactivated', $warehouse, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Warehouse deactivated.', 'alert-type' => 'success']);
    }

    public function department(Request $request)
    {
        $data = $request->validate(['company_id' => ['required', 'exists:companies,id'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:30', 'alpha_dash']]);
        if (auth()->user()?->company_id && (int) $data['company_id'] !== (int) auth()->user()->company_id) abort(403);
        if (Department::where('company_id', $data['company_id'])->where('code', $data['code'])->exists()) return back()->withErrors(['code' => 'This department code already exists in the company.'])->withInput();
        $department = Department::create($data + ['is_active' => true]);
        app(AuditService::class)->record('department.created', $department, null, $department->toArray());
        return back()->with(['message' => 'Department created.', 'alert-type' => 'success']);
    }

    public function updateDepartment(Request $request, int $id)
    {
        $department = Department::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (Department::where('company_id', $department->company_id)->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This department code already exists in the company.'])->withInput();
        }
        $before = $department->toArray();
        $department->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('department.updated', $department, $before, $department->fresh()->toArray());
        return back()->with(['message' => 'Department updated.', 'alert-type' => 'success']);
    }

    public function deactivateDepartment(int $id)
    {
        $department = Department::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->firstOrFail();
        if (\App\Models\User::where('department_id', $id)->where('is_active', true)->exists()) {
            return back()->withErrors(['department' => 'Reassign active users before deactivating this department.']);
        }
        if ($department->is_active) {
            $department->update(['is_active' => false]);
            app(AuditService::class)->record('department.deactivated', $department, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Department deactivated.', 'alert-type' => 'success']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'exists:branches,id'], 'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:30', 'alpha_dash'], 'address' => ['nullable', 'string', 'max:2000'], 'currency_code' => ['nullable', 'string', 'size:3'], 'default_tax_mode' => ['nullable', 'in:exclusive,inclusive'], 'allow_negative_stock' => ['nullable', 'boolean'], 'pos_settings' => ['nullable', 'array']]);
        $branch = Branch::findOrFail($data['branch_id']);
        if (auth()->user()?->company_id && (int) $branch->company_id !== (int) auth()->user()->company_id) abort(403);
        if (!empty($data['warehouse_id']) && !Warehouse::whereKey($data['warehouse_id'])->where('branch_id', $branch->id)->where('is_active', true)->exists()) return back()->withErrors(['warehouse_id' => 'The selected fulfillment warehouse must be active and belong to the selected branch.'])->withInput();
        if (Store::where('branch_id', $data['branch_id'])->where('code', $data['code'])->exists()) return back()->withErrors(['code' => 'This store code already exists in the branch.'])->withInput();
        $store = Store::create($data + ['is_active' => true, 'currency_code' => strtoupper($data['currency_code'] ?? '') ?: null, 'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false)]);
        app(AuditService::class)->record('store.created', $store, null, $store->toArray());
        return back()->with(['message' => 'Store created.', 'alert-type' => 'success']);
    }

    public function updateStore(Request $request, int $id)
    {
        $store = Store::with('branch')->whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'address' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'default_tax_mode' => ['nullable', 'in:exclusive,inclusive'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'pos_settings' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (Store::where('branch_id', $store->branch_id)->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This store code already exists in the branch.'])->withInput();
        }
        if (!empty($data['warehouse_id']) && !Warehouse::whereKey($data['warehouse_id'])->where('branch_id', $store->branch_id)->where('is_active', true)->exists()) {
            return back()->withErrors(['warehouse_id' => 'The selected fulfillment warehouse must be active and belong to this store branch.'])->withInput();
        }
        $data['currency_code'] = strtoupper($data['currency_code'] ?? '') ?: null;
        $before = $store->toArray();
        $store->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('store.updated', $store, $before, $store->fresh()->toArray());
        return back()->with(['message' => 'Store updated.', 'alert-type' => 'success']);
    }

    public function deactivateStore(int $id)
    {
        $store = Store::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();
        if (\App\Models\SalesOrder::where('store_id', $id)->whereIn('status', ['submitted', 'approved', 'partially_fulfilled'])->exists()) {
            return back()->withErrors(['store' => 'Complete or cancel active sales orders before deactivating this store.']);
        }
        if ($store->is_active) {
            $store->update(['is_active' => false]);
            app(AuditService::class)->record('store.deactivated', $store, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Store deactivated.', 'alert-type' => 'success']);
    }

    public function location(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'parent_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash'],
            'type' => ['required', 'in:zone,rack,shelf,bin'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'capacity_volume_m3' => ['nullable', 'numeric', 'min:0'],
        ]);
        $warehouse = Warehouse::whereKey($data['warehouse_id'])
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->first();
        if (!$warehouse) abort(403, 'The selected warehouse is outside the authenticated company.');
        $exists = InventoryLocation::where('warehouse_id', $data['warehouse_id'])->where('code', $data['code'])->exists();
        if ($exists) return back()->withErrors(['code' => 'This location code already exists in the warehouse.'])->withInput();
        if (!empty($data['parent_id'])) {
            $parent = InventoryLocation::whereKey($data['parent_id'])->where('warehouse_id', $warehouse->id)->first();
            if (!$parent) return back()->withErrors(['parent_id' => 'The parent location must belong to the selected warehouse.'])->withInput();
            try { app(\App\Services\InventoryLocationHierarchyService::class)->assertParentLevel($data['type'], $parent->type); }
            catch (\InvalidArgumentException $exception) { return back()->withErrors(['parent_id' => $exception->getMessage()])->withInput(); }
        }
        $location = InventoryLocation::create($data);
        app(AuditService::class)->record('inventory_location.created', $location, null, $location->toArray());
        return back()->with(['message' => 'Inventory location created.', 'alert-type' => 'success']);
    }

    public function updateLocation(Request $request, int $id)
    {
        $location = InventoryLocation::with('warehouse.branch')
            ->whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('warehouse.branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:inventory_locations,id', 'not_in:'.$id],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash'],
            'type' => ['required', 'in:zone,rack,shelf,bin'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'capacity_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $warehouse = $location->warehouse;
        if (InventoryLocation::where('warehouse_id', $warehouse->id)->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['code' => 'This location code already exists in the warehouse.'])->withInput();
        }
        if (!empty($data['parent_id'])) {
            $parent = InventoryLocation::whereKey($data['parent_id'])->where('warehouse_id', $warehouse->id)->first();
            if (!$parent) return back()->withErrors(['parent_id' => 'The parent location must belong to the same warehouse.'])->withInput();
            try { app(\App\Services\InventoryLocationHierarchyService::class)->assertParentLevel($data['type'], $parent->type); }
            catch (\InvalidArgumentException $exception) { return back()->withErrors(['parent_id' => $exception->getMessage()])->withInput(); }
            if ($this->locationDescendsFrom($id, (int) $data['parent_id'])) return back()->withErrors(['parent_id' => 'A location cannot be nested under one of its descendants.'])->withInput();
        }

        if (array_key_exists('is_active', $data) && !$data['is_active'] && $location->is_active) {
            $blocker = app(InventoryLocationLifecycleService::class)->deactivationBlocker($location);
            if ($blocker !== null) return back()->withErrors(['location' => $blocker])->withInput();
        }

        $before = $location->toArray();
        $location->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('inventory_location.updated', $location, $before, $location->fresh()->toArray());
        return back()->with(['message' => 'Inventory location updated.', 'alert-type' => 'success']);
    }

    public function deactivateLocation(int $id)
    {
        $location = InventoryLocation::whereKey($id)
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->whereHas('warehouse.branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId)))
            ->firstOrFail();
        if ($location->is_active) {
            $blocker = app(InventoryLocationLifecycleService::class)->deactivationBlocker($location);
            if ($blocker !== null) return back()->withErrors(['location' => $blocker]);
            $location->update(['is_active' => false]);
            app(AuditService::class)->record('inventory_location.deactivated', $location, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Inventory location deactivated.', 'alert-type' => 'success']);
    }

    private function locationDescendsFrom(int $locationId, int $possibleChildId): bool
    {
        $current = $possibleChildId;
        $visited = [];
        while ($current && !in_array($current, $visited, true)) {
            if ($current === $locationId) return true;
            $visited[] = $current;
            $current = (int) (InventoryLocation::whereKey($current)->value('parent_id') ?? 0);
        }
        return false;
    }
}
