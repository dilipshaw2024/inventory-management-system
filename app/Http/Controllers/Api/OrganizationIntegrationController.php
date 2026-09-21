<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationRule;
use App\Models\Warehouse;
use App\Models\Store;
use App\Models\Department;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\IntegrationCursorService;
use App\Services\AuditService;
use App\Services\InventoryLocationLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationIntegrationController extends Controller
{
    public function updateCompany(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        if (!$companyId) abort(403, 'A company is required for this operation.');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('companies', 'code')->ignore($companyId)],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_currency' => ['sometimes', 'required', 'string', 'size:3'], 'consolidation_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'parent_company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('companies', 'id')], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('base_currency', $data)) $data['base_currency'] = strtoupper($data['base_currency']);
        if (array_key_exists('consolidation_currency', $data) && $data['consolidation_currency'] !== null) $data['consolidation_currency'] = strtoupper($data['consolidation_currency']);
        $company = Company::whereKey($companyId)->firstOrFail();
        if (array_key_exists('parent_company_id', $data)) {
            $parentId = $data['parent_company_id'];
            if ($parentId !== null && (int) $parentId === (int) $company->id) abort(422, 'A company cannot be its own parent.');
            if ($parentId !== null && $this->companyTreeContains((int) $company->id, (int) $parentId)) abort(422, 'A company cannot be assigned beneath one of its subsidiaries.');
        }
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $company->is_active) $this->assertCompanyCanDeactivate($company);
        $before = $company->only(array_keys($data));
        $company->update($data);
        app(AuditService::class)->record('organization.company.updated', $company, $before, $company->fresh()->only(array_keys($data)));
        return response()->json(['data' => $company->fresh(), 'status' => 'updated']);
    }

    private function companyTreeContains(int $companyId, int $candidateId): bool
    {
        $children = Company::where('parent_company_id', $companyId)->pluck('id');
        if ($children->contains($candidateId)) return true;
        return $children->contains(fn ($childId): bool => $this->companyTreeContains((int) $childId, $candidateId));
    }

    public function deactivateCompany(Request $request): JsonResponse
    {
        $company = Company::whereKey($request->user()?->company_id)->firstOrFail();
        if ($company->is_active) $this->assertCompanyCanDeactivate($company);
        $before = $company->only(['is_active']);
        $company->update(['is_active' => false]);
        app(AuditService::class)->record('organization.company.deactivated', $company, $before, ['is_active' => false]);
        return response()->json(['data' => $company->fresh(), 'status' => 'deactivated']);
    }

    public function updateBranch(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->updateRules(['tax_number' => ['nullable', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:2000'], 'phone' => ['nullable', 'string', 'max:50']]));
        return $this->updateMaster(new Branch(), $id, $data, 'organization.branch.updated');
    }

    public function updateWarehouse(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->updateRules(['branch_id' => ['sometimes', 'required', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId))], 'warehouse_type' => ['sometimes', 'required', 'in:standard,distribution,retail,manufacturing,quarantine,transit'], 'manager_name' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000']]));
        $warehouse = $this->organizationScope(new Warehouse())->findOrFail($id);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $warehouse->is_active) $this->assertWarehouseCanDeactivate($warehouse);
        return $this->updateMaster($warehouse, null, $data, 'organization.warehouse.updated');
    }

    public function updateStore(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->updateRules(['branch_id' => ['sometimes', 'required', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId))], 'warehouse_id' => ['nullable', 'integer'], 'address' => ['nullable', 'string', 'max:2000'], 'allow_negative_stock' => ['nullable', 'boolean'], 'pos_settings' => ['nullable', 'array']]));
        $store = $this->organizationScope(new Store())->findOrFail($id);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $store->is_active) $this->assertStoreCanDeactivate($store);
        $branchId = (int) ($data['branch_id'] ?? $store->branch_id);
        if (!empty($data['warehouse_id']) && !Warehouse::whereKey($data['warehouse_id'])->whereHas('branch', fn ($q) => $q->where('company_id', $companyId)->whereKey($branchId))->exists()) abort(422, 'Warehouse is not authorized for this branch.');
        return $this->updateMaster($store, null, $data, 'organization.store.updated');
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->updateRules([]));
        $department = $this->organizationScope(new Department())->findOrFail($id);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $department->is_active) $this->assertDepartmentCanDeactivate($department);
        return $this->updateMaster($department, null, $data, 'organization.department.updated');
    }

    public function updateLocation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['external_reference' => ['sometimes', 'nullable', 'string', 'max:150'], 'name' => ['sometimes', 'required', 'string', 'max:255'], 'code' => ['sometimes', 'required', 'string', 'max:100'], 'parent_id' => ['sometimes', 'nullable', 'integer'], 'capacity' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'capacity_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'capacity_volume_m3' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']]);
        $location = $this->organizationScope(new InventoryLocation())->findOrFail($id);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $location->is_active) $this->assertLocationCanDeactivate($location);
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = $this->organizationScope(new InventoryLocation())->where('warehouse_id', $location->warehouse_id)->where('id', '<>', $location->id)->whereKey($data['parent_id'])->first();
            if (!$parent) abort(422, 'Parent location must belong to the same warehouse.');
            try { app(\App\Services\InventoryLocationHierarchyService::class)->assertParentLevel($location->type, $parent->type); }
            catch (\InvalidArgumentException $exception) { abort(422, $exception->getMessage()); }
        }
        return $this->updateMaster($location, null, $data, 'organization.location.updated');
    }

    public function deactivate(Request $request, string $type, int $id): JsonResponse
    {
        $models = ['branch' => Branch::class, 'warehouse' => Warehouse::class, 'store' => Store::class, 'department' => Department::class, 'location' => InventoryLocation::class];
        if (!isset($models[$type])) abort(404, 'Unknown organization master.');
        $record = $this->organizationScope(new $models[$type]())->findOrFail($id);
        if ($record->is_active) {
            if ($type === 'branch') $this->assertBranchCanDeactivate($record);
            if ($type === 'warehouse') $this->assertWarehouseCanDeactivate($record);
            if ($type === 'store') $this->assertStoreCanDeactivate($record);
            if ($type === 'department') $this->assertDepartmentCanDeactivate($record);
            if ($type === 'location') $this->assertLocationCanDeactivate($record);
        }
        $before = $record->only(['is_active']);
        $record->update(['is_active' => false]);
        app(AuditService::class)->record('organization.'.$type.'.deactivated', $record, $before, $record->fresh()->only(['is_active']));
        return response()->json(['data' => $record->fresh(), 'status' => 'deactivated']);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->masterRules(['name' => ['required', 'string', 'max:255'], 'tax_number' => ['nullable', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:2000'], 'phone' => ['nullable', 'string', 'max:50']]));
        return $this->createMaster($request, new Branch(), $data + ['company_id' => $companyId], 'organization.branch.created');
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->masterRules(['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId))], 'warehouse_type' => ['sometimes', 'required', 'in:standard,distribution,retail,manufacturing,quarantine,transit'], 'manager_name' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000']]));
        return $this->createMaster($request, new Warehouse(), $data, 'organization.warehouse.created');
    }

    public function storeStore(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->masterRules(['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId))], 'warehouse_id' => ['nullable', 'integer'], 'address' => ['nullable', 'string', 'max:2000'], 'allow_negative_stock' => ['nullable', 'boolean'], 'pos_settings' => ['nullable', 'array']]));
        if (!empty($data['warehouse_id']) && !Warehouse::whereKey($data['warehouse_id'])->whereHas('branch', fn ($q) => $q->where('company_id', $companyId)->whereKey($data['branch_id']))->exists()) abort(422, 'Warehouse is not authorized for this branch.');
        return $this->createMaster($request, new Store(), $data, 'organization.store.created');
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate($this->masterRules([]));
        return $this->createMaster($request, new Department(), $data + ['company_id' => $companyId], 'organization.department.created');
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'warehouse_id' => ['required', 'integer'], 'parent_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:warehouse,zone,rack,shelf,bin'], 'capacity' => ['nullable', 'numeric', 'min:0'], 'capacity_weight_kg' => ['nullable', 'numeric', 'min:0'], 'capacity_volume_m3' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['nullable', 'boolean']]);
        $warehouse = Warehouse::with('branch')->whereKey($data['warehouse_id'])->firstOrFail();
        if ((int) $warehouse->branch->company_id !== (int) $companyId) abort(403, 'Warehouse is not authorized for this company.');
        if (($data['parent_id'] ?? null) !== null) {
            $parent = InventoryLocation::whereKey($data['parent_id'])->where('warehouse_id', $warehouse->id)->first();
            if (!$parent) abort(422, 'Parent location must belong to the same warehouse.');
            try { app(\App\Services\InventoryLocationHierarchyService::class)->assertParentLevel($data['type'], $parent->type); }
            catch (\InvalidArgumentException $exception) { abort(422, $exception->getMessage()); }
        }
        return $this->createMaster($request, new InventoryLocation(), $data, 'organization.location.created');
    }

    private function masterRules(array $extra): array
    {
        return array_merge(['external_reference' => ['nullable', 'string', 'max:150'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean']], $extra);
    }

    private function updateRules(array $extra): array
    {
        return array_merge(['external_reference' => ['sometimes', 'nullable', 'string', 'max:150'], 'name' => ['sometimes', 'required', 'string', 'max:255'], 'code' => ['sometimes', 'required', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean']], $extra);
    }

    private function organizationScope(object $model)
    {
        $companyId = auth()->user()?->company_id;
        $query = $model->newQuery();
        if ($model instanceof InventoryLocation) {
            return $query->whereHas('warehouse.branch', fn ($branch) => $branch->where('company_id', $companyId)->orWhereNull('company_id'));
        }
        if ($model instanceof Warehouse || $model instanceof Store) {
            return $query->whereHas('branch', fn ($branch) => $branch->where('company_id', $companyId));
        }
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    private function createMaster(Request $request, object $model, array $data, string $action): JsonResponse
    {
        if (!empty($data['external_reference'])) {
            $existing = $this->organizationScope($model)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $record = DB::transaction(function () use ($model, $data, $action): object {
            $record = $model->newQuery()->create($data);
            app(AuditService::class)->record($action, $record, null, $record->toArray());
            return $record;
        });
        return response()->json(['data' => $record, 'status' => 'created'], 201);
    }

    private function updateMaster(object $model, ?int $id, array $data, string $action): JsonResponse
    {
        $record = $id === null ? $model : $this->organizationScope($model)->findOrFail($id);
        $before = $record->only(array_keys($data));
        $record->update($data);
        $after = $record->fresh()->only(array_keys($data));
        app(AuditService::class)->record($action, $record, $before, $after);
        return response()->json(['data' => $record->fresh(), 'status' => 'updated']);
    }

    private function assertCompanyCanDeactivate(Company $company): void
    {
        if ($company->branches()->where('is_active', true)->exists() || $company->departments()->where('is_active', true)->exists()) {
            abort(422, 'Deactivate active branches and departments before deactivating this company.');
        }
    }

    private function assertBranchCanDeactivate(Branch $branch): void
    {
        if ($branch->warehouses()->where('is_active', true)->exists() || $branch->stores()->where('is_active', true)->exists()) {
            abort(422, 'Deactivate active warehouses and stores before deactivating this branch.');
        }
    }

    private function assertWarehouseCanDeactivate(Warehouse $warehouse): void
    {
        if ($warehouse->locations()->where('is_active', true)->exists()) {
            abort(422, 'Deactivate all active storage locations before deactivating this warehouse.');
        }
    }

    private function assertStoreCanDeactivate(Store $store): void
    {
        if (SalesOrder::where('store_id', $store->id)->whereIn('status', ['submitted', 'approved', 'partially_fulfilled'])->exists()) {
            abort(422, 'Complete or cancel active sales orders before deactivating this store.');
        }
    }

    private function assertDepartmentCanDeactivate(Department $department): void
    {
        if (User::where('department_id', $department->id)->where('is_active', true)->exists()) {
            abort(422, 'Reassign active users before deactivating this department.');
        }
    }

    private function assertLocationCanDeactivate(InventoryLocation $location): void
    {
        $message = app(InventoryLocationLifecycleService::class)->deactivationBlocker($location);
        if ($message !== null) abort(422, $message);
    }

    public function stores(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $stores = $this->organizationScope(new Store())->with(['branch', 'warehouse'])
            ->when($data['branch_id'] ?? null, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($stores, $request, 'organization.stores', (int) ($data['per_page'] ?? 50));
    }

    public function departments(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $departments = $this->organizationScope(new Department())->with('company')
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($departments, $request, 'organization.departments', (int) ($data['per_page'] ?? 50));
    }

    public function companies(Request $request): JsonResponse
    {
        $data = $request->validate(['updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companies = Company::with(['branches', 'departments', 'parentCompany'])
            ->when($request->user()?->company_id, fn ($query, $id) => $query->whereKey($id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($companies, $request, 'organization.companies', (int) ($data['per_page'] ?? 50));
    }

    public function branches(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $branches = $this->organizationScope(new Branch())->with('company')
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($branches, $request, 'organization.branches', (int) ($data['per_page'] ?? 50));
    }

    public function warehouses(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'warehouse_type' => ['nullable', 'in:standard,distribution,retail,manufacturing,quarantine,transit'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $warehouses = $this->organizationScope(new Warehouse())->with('branch')
            ->when($data['branch_id'] ?? null, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($data['warehouse_type'] ?? null, fn ($query, $type) => $query->where('warehouse_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($warehouses, $request, 'organization.warehouses', (int) ($data['per_page'] ?? 50));
    }

    public function locations(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:warehouse,zone,rack,shelf,bin'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $locations = $this->organizationScope(new InventoryLocation())->with(['warehouse', 'parent', 'rules.product', 'rules.category'])
            ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($locations, $request, 'organization.locations', (int) ($data['per_page'] ?? 50));
    }

    public function storeLocationRule(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['location_id' => ['required', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)], 'product_id' => ['nullable', 'integer', $owned('products')], 'category_id' => ['nullable', 'integer', $owned('categories')], 'rule_type' => ['required', 'in:allow,deny']]);
        if (empty($data['product_id']) === empty($data['category_id'])) abort(422, 'Specify exactly one product or category for a location rule.');
        $location = $this->organizationScope(new InventoryLocation())->findOrFail($data['location_id']);
        $rule = InventoryLocationRule::firstOrCreate(['location_id' => $location->id, 'product_id' => $data['product_id'] ?? null, 'category_id' => $data['category_id'] ?? null, 'rule_type' => $data['rule_type']], ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('organization.location_rule.created', $rule, null, $rule->toArray());
        return response()->json(['data' => $rule->load('location', 'product', 'category'), 'status' => $rule->wasRecentlyCreated ? 'created' : 'duplicate_ignored'], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function deactivateLocationRule(Request $request, int $id): JsonResponse
    {
        $rule = InventoryLocationRule::whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $request->user()?->company_id))->findOrFail($id);
        if ($rule->is_active) { $rule->update(['is_active' => false]); app(AuditService::class)->record('organization.location_rule.deactivated', $rule, ['is_active' => true], ['is_active' => false]); }
        return response()->json(['data' => $rule->fresh()->load('location', 'product', 'category'), 'status' => 'deactivated']);
    }
}
