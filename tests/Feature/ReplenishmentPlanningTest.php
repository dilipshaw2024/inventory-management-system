<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use App\Services\ReplenishmentPlanningService;
use App\Services\SupplierProductPriceService;
use App\Services\PlanningCalendarService;
use App\Services\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ReplenishmentPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_time_buffer_is_included_in_replenishment_receipt_date(): void
    {
        $company = Company::create(['name' => 'Planning Co', 'code' => 'PLAN-TEST']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'MAIN']);
        $warehouse = $branch->warehouses()->create(['name' => 'Central', 'code' => 'CENTRAL']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Bin A', 'code' => 'BIN-A', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'General', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'name' => 'Buffered item',
            'reorder_level' => 10,
            'quantity' => 0,
            'status' => 1,
        ]);
        InventoryReplenishmentPolicy::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'reorder_point' => 5,
            'min_stock' => 10,
            'lead_time_days' => 4,
            'safety_time_days' => 3,
            'is_active' => true,
        ]);

        $availability = Mockery::mock(InventoryAvailabilityService::class);
        $availability->shouldReceive('available')->once()->with(Mockery::on(fn ($value): bool => $value instanceof Product && (int) $value->id === (int) $product->id), true, $location->id, $company->id)->andReturn(2.0);
        $this->app->instance(InventoryAvailabilityService::class, $availability);
        $prices = Mockery::mock(SupplierProductPriceService::class);
        $prices->shouldReceive('bestFor')->once()->andReturnNull();
        $this->app->instance(SupplierProductPriceService::class, $prices);

        $proposal = app(ReplenishmentPlanningService::class)->proposalsForCompany($company->id)->first();

        $this->assertNotNull($proposal);
        $this->assertSame(4, $proposal['lead_time_days']);
        $this->assertSame(3, $proposal['safety_time_days']);
        $this->assertSame(7, $proposal['planning_days']);
        $this->assertSame(8.0, (float) $proposal['quantity']);
        $this->assertSame(app(PlanningCalendarService::class)->addWorkingDays($proposal['suggested_order_date'], 7, $company->id)->toDateString(), $proposal['expected_receipt_date']);
    }

    public function test_inactive_supplier_products_are_not_replenishment_candidates(): void
    {
        $company = Company::create(['name' => 'Inactive Supplier Co', 'code' => 'INACTIVE-SUP-TEST']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Inactive Supplier', 'is_active' => false]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Inactive Supplier Category', 'status' => 1]);
        Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Unavailable supplier item',
            'reorder_level' => 10, 'quantity' => 0, 'status' => 1,
        ]);

        $this->assertCount(0, app(ReplenishmentPlanningService::class)->proposalsForCompany($company->id));
    }

    public function test_time_phased_replenishment_projects_daily_receipts_and_balances(): void
    {
        $company = Company::create(['name' => 'Time Phased Co', 'code' => 'TIME-PHASED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Planning Branch', 'code' => 'TP-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Planning Warehouse', 'code' => 'TP-WAREHOUSE']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Planning Bin', 'code' => 'TP-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Planning Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Time Each', 'status' => 1]);
        $category = Category::create(['name' => 'Time Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Time phased item', 'reorder_level' => 5, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'reorder_point' => 5, 'min_stock' => 10, 'lead_time_days' => 0, 'safety_time_days' => 0, 'is_active' => true]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/replenishment/time-phased?horizon_days=3');

        $response->assertOk()->assertJsonPath('meta.horizon_days', 3)->assertJsonPath('data.0.product_id', $product->id)->assertJsonCount(3, 'data.0.buckets')->assertJsonPath('data.0.buckets.0.planned_replenishment_receipts', 10)->assertJsonPath('data.0.buckets.0.projected_balance', 10);
    }

    public function test_browser_planning_report_applies_selected_location_policy(): void
    {
        $company = Company::create(['name' => 'Browser Planning Co', 'code' => 'BROWSER-PLAN']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Browser Branch', 'code' => 'BROWSER-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Browser Warehouse', 'code' => 'BROWSER-WAREHOUSE']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Browser Bin', 'code' => 'BROWSER-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Browser Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Browser Each', 'status' => 1]);
        $category = Category::create(['name' => 'Browser Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Location policy item', 'reorder_level' => 99, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'reorder_point' => 5, 'min_stock' => 7, 'max_stock' => 20, 'is_active' => true]);
        $permission = Permission::create(['code' => 'reports.view', 'name' => 'View reports', 'module' => 'reports']);
        $role = Role::create(['code' => 'browser-planner', 'name' => 'Browser planner', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->get('/planning/report?type=low&location_id='.$location->id);

        $response->assertOk()->assertViewHas('locationId', $location->id)->assertViewHas('products', function ($products) use ($product): bool {
            $row = $products->getCollection()->firstWhere('id', $product->id);
            return $row !== null && (float) $row->planning_reorder_level === 5.0 && (float) $row->planning_min_stock === 7.0 && (float) $row->planning_max_stock === 20.0;
        });
    }

    public function test_planning_dashboard_counts_location_policy_exceptions_once_per_product(): void
    {
        $company = Company::create(['name' => 'Dashboard Planning Co', 'code' => 'DASHBOARD-PLAN']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Dashboard Branch', 'code' => 'DASHBOARD-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Dashboard Warehouse', 'code' => 'DASHBOARD-WAREHOUSE']);
        $firstLocation = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Dashboard Bin A', 'code' => 'DASHBOARD-A', 'type' => 'bin', 'is_active' => true]);
        $secondLocation = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Dashboard Bin B', 'code' => 'DASHBOARD-B', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Dashboard Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dashboard Each', 'status' => 1]);
        $category = Category::create(['name' => 'Dashboard Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Dashboard policy item', 'reorder_level' => 99, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $firstLocation->id, 'reorder_point' => 5, 'max_stock' => 20, 'is_active' => true]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $secondLocation->id, 'reorder_point' => 5, 'max_stock' => 20, 'is_active' => true]);

        $counts = app(DashboardMetricsService::class)->thresholdCounts(collect([$product]), $company->id, [$product->id => 0]);

        $this->assertSame(['low' => 1, 'excess' => 0], $counts);
    }
}
