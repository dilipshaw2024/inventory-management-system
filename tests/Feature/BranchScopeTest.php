<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\InventoryStatusBalance;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_branch_user_sees_only_locations_in_their_branch(): void
    {
        $company = Company::create(['name' => 'Branch Scope Co', 'code' => 'BRANCH-SCOPE']);
        $first = Branch::create(['company_id' => $company->id, 'name' => 'North', 'code' => 'NORTH']);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'South', 'code' => 'SOUTH']);
        $firstWarehouse = $first->warehouses()->create(['name' => 'North Warehouse', 'code' => 'NORTH-WH']);
        $secondWarehouse = $second->warehouses()->create(['name' => 'South Warehouse', 'code' => 'SOUTH-WH']);
        $firstLocation = InventoryLocation::create(['warehouse_id' => $firstWarehouse->id, 'name' => 'North Bin', 'code' => 'NORTH-BIN', 'type' => 'bin', 'is_active' => true]);
        InventoryLocation::create(['warehouse_id' => $secondWarehouse->id, 'name' => 'South Bin', 'code' => 'SOUTH-BIN', 'type' => 'bin', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id, 'branch_id' => $first->id]);
        Sanctum::actingAs($user, ['warehouse:read']);

        $response = $this->getJson('/api/integration/warehouse/location-utilization');

        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.location_id', $firstLocation->id);
    }

    public function test_assigned_branch_user_cannot_write_warehouse_in_another_branch(): void
    {
        $company = Company::create(['name' => 'Branch Write Co', 'code' => 'BRANCH-WRITE']);
        $first = Branch::create(['company_id' => $company->id, 'name' => 'North', 'code' => 'WRITE-NORTH']);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'South', 'code' => 'WRITE-SOUTH']);
        $user = User::factory()->create(['company_id' => $company->id, 'branch_id' => $first->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/warehouses', [
            'name' => 'Unauthorized Warehouse',
            'code' => 'UNAUTHORIZED-WH',
            'branch_id' => $second->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('warehouses', ['code' => 'UNAUTHORIZED-WH']);
    }

    public function test_company_cannot_be_deactivated_while_active_organization_children_exist(): void
    {
        $company = Company::create(['name' => 'Company Guard Co', 'code' => 'COMPANY-GUARD']);
        Branch::create(['company_id' => $company->id, 'name' => 'Active Branch', 'code' => 'GUARD-BRANCH', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/company/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deactivate active branches and departments before deactivating this company.');

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_active' => true]);
    }

    public function test_branch_cannot_be_deactivated_while_active_warehouse_exists(): void
    {
        $company = Company::create(['name' => 'Branch Guard Co', 'code' => 'BRANCH-GUARD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Active Branch', 'code' => 'BRANCH-GUARD-1', 'is_active' => true]);
        $branch->warehouses()->create(['name' => 'Active Warehouse', 'code' => 'BRANCH-GUARD-WH', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/branch/'.$branch->id.'/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deactivate active warehouses and stores before deactivating this branch.');

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
    }

    public function test_warehouse_cannot_be_deactivated_while_active_location_exists(): void
    {
        $company = Company::create(['name' => 'Warehouse Guard Co', 'code' => 'WAREHOUSE-GUARD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Guard Branch', 'code' => 'WAREHOUSE-GUARD-B']);
        $warehouse = $branch->warehouses()->create(['name' => 'Active Warehouse', 'code' => 'WAREHOUSE-GUARD-WH', 'is_active' => true]);
        InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Active Bin', 'code' => 'WAREHOUSE-GUARD-BIN', 'type' => 'bin', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/warehouse/'.$warehouse->id.'/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deactivate all active storage locations before deactivating this warehouse.');

        $this->patchJson('/api/integration/organization/warehouses/'.$warehouse->id, [
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'is_active' => false,
        ])->assertStatus(422);

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'is_active' => true]);
    }

    public function test_store_cannot_be_deactivated_while_active_sales_order_exists(): void
    {
        $company = Company::create(['name' => 'Store Guard Co', 'code' => 'STORE-GUARD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Store Branch', 'code' => 'STORE-GUARD-B']);
        $store = $branch->stores()->create(['name' => 'Active Store', 'code' => 'STORE-GUARD-S', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Guard Customer']);
        SalesOrder::create(['company_id' => $company->id, 'store_id' => $store->id, 'customer_id' => $customer->id, 'order_no' => 'STORE-GUARD-SO', 'date' => now()->toDateString(), 'status' => 'approved']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/store/'.$store->id.'/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Complete or cancel active sales orders before deactivating this store.');

        $this->patchJson('/api/integration/organization/stores/'.$store->id, [
            'name' => $store->name,
            'code' => $store->code,
            'is_active' => false,
        ])->assertStatus(422);

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'is_active' => true]);
    }

    public function test_department_cannot_be_deactivated_while_active_user_exists(): void
    {
        $company = Company::create(['name' => 'Department Guard Co', 'code' => 'DEPARTMENT-GUARD']);
        $department = \App\Models\Department::create(['company_id' => $company->id, 'name' => 'Operations', 'code' => 'DEPARTMENT-GUARD-D', 'is_active' => true]);
        User::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/department/'.$department->id.'/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Reassign active users before deactivating this department.');

        $this->patchJson('/api/integration/organization/departments/'.$department->id, [
            'name' => $department->name,
            'code' => $department->code,
            'is_active' => false,
        ])->assertStatus(422);

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'is_active' => true]);
    }

    public function test_location_cannot_be_deactivated_while_quality_held_stock_exists(): void
    {
        $company = Company::create(['name' => 'Location Guard Co', 'code' => 'LOCATION-GUARD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Location Branch', 'code' => 'LOCATION-GUARD-B']);
        $warehouse = $branch->warehouses()->create(['name' => 'Location Warehouse', 'code' => 'LOCATION-GUARD-WH']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Held Bin', 'code' => 'LOCATION-GUARD-BIN', 'type' => 'bin', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'LOCATION-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Location Category', 'status' => 1]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Location Supplier', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Held Item', 'status' => 1]);
        InventoryStatusBalance::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'status' => 'quarantine', 'quantity' => 2]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->postJson('/api/integration/organization/location/'.$location->id.'/deactivate')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Move or issue all stock before deactivating this location.');

        $this->patchJson('/api/integration/organization/locations/'.$location->id, [
            'name' => $location->name,
            'code' => $location->code,
            'is_active' => false,
        ])->assertStatus(422);

        $this->assertDatabaseHas('inventory_locations', ['id' => $location->id, 'is_active' => true]);
    }
}
