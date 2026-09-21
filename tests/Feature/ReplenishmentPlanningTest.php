<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
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

    public function test_replenishment_scenario_is_read_only_and_applies_daily_demand_override(): void
    {
        $company = Company::create(['name' => 'Scenario Planning Co', 'code' => 'SCENARIO-PLAN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Scenario Branch', 'code' => 'SCENARIO-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Scenario Warehouse', 'code' => 'SCENARIO-WAREHOUSE']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Scenario Bin', 'code' => 'SCENARIO-BIN', 'type' => 'bin', 'is_active' => true]);
        $sourceLocation = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Scenario Source', 'code' => 'SCENARIO-SOURCE', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Scenario Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Scenario Each', 'status' => 1]);
        $category = Category::create(['name' => 'Scenario Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Scenario item', 'reorder_level' => 5, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'reorder_point' => 5, 'min_stock' => 10, 'max_stock' => 10, 'lead_time_days' => 5, 'is_active' => true]);
        $transfer = InventoryTransfer::create(['company_id' => $company->id, 'transfer_no' => 'SCENARIO-TRANSFER-1', 'date' => now()->toDateString(), 'expected_arrival' => now()->toDateString(), 'description' => 'Scenario receipt', 'status' => 'pending']);
        InventoryTransferLine::create(['transfer_id' => $transfer->id, 'product_id' => $product->id, 'source_location_id' => $sourceLocation->id, 'destination_location_id' => $location->id, 'quantity' => 5, 'unit_cost' => 4]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/replenishment/scenario?product_id='.$product->id.'&location_id='.$location->id.'&horizon_days=3&daily_demand=2');

        $response->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.horizon_days', 3)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.daily_demand_override', 2)
            ->assertJsonPath('data.0.scenario_daily_demand', 2)
            ->assertJsonPath('data.0.buckets.0.open_transfer_receipt', 5)
            ->assertJsonPath('data.0.buckets.0.scenario_projected_balance', 3)
            ->assertJsonPath('data.0.buckets.0.scenario_shortfall', 7);
        Sanctum::actingAs($user, ['inventory:write']);
        $saved = $this->postJson('/api/inventory/replenishment/scenarios', [
            'name' => 'Demand stress scenario', 'external_reference' => 'SCENARIO-SAVED-1',
            'product_id' => $product->id, 'location_id' => $location->id, 'horizon_days' => 3, 'daily_demand' => 2,
        ]);
        $saved->assertCreated()->assertJsonPath('status', 'saved')->assertJsonPath('data.name', 'Demand stress scenario')->assertJsonPath('data.result_snapshot.0.product_id', $product->id);
        $scenarioId = $saved->json('data.id');
        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/replenishment/scenarios')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $scenarioId);
        $this->getJson('/api/inventory/replenishment/scenarios/'.$scenarioId)->assertOk()->assertJsonPath('data.result_snapshot.0.buckets.0.scenario_shortfall', 7);
        Sanctum::actingAs($user, ['inventory:write']);
        $this->postJson('/api/inventory/replenishment/scenarios', [
            'name' => 'Replay scenario', 'external_reference' => 'SCENARIO-SAVED-1', 'product_id' => $product->id, 'location_id' => $location->id,
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $scenarioId);
        $permission = Permission::create(['code' => 'reports.view', 'name' => 'View reports', 'module' => 'reports']);
        $savePermission = Permission::create(['code' => 'inventory.post', 'name' => 'Post inventory', 'module' => 'inventory']);
        $role = Role::create(['code' => 'scenario-planner', 'name' => 'Scenario planner', 'is_active' => true]);
        $role->permissions()->attach([$permission->id, $savePermission->id]); $user->roles()->attach($role);
        $this->actingAs($user)->get('/planning/replenishment-scenario?location_id='.$location->id.'&horizon_days=3&daily_demand=2')->assertOk()->assertSee('Scenario item')->assertSee('Demand stress scenario')->assertViewHas('scenarios', fn ($rows): bool => $rows->first()['buckets']->first()['open_transfer_receipt'] === 5.0);
        $this->actingAs($user)->post('/planning/replenishment-scenario/save', ['name' => 'Browser saved scenario', 'product_id' => $product->id, 'location_id' => $location->id, 'horizon_days' => 3, 'demand_multiplier' => 1, 'daily_demand' => 2])->assertRedirect();
        $this->assertDatabaseHas('replenishment_scenarios', ['company_id' => $company->id, 'name' => 'Browser saved scenario', 'created_by' => $user->id]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_multi_echelon_replenishment_exposes_hierarchy_and_nets_transfer_supply(): void
    {
        $company = Company::create(['name' => 'Network Planning Co', 'code' => 'NETWORK-PLAN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Network Branch', 'code' => 'NETWORK-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Network Warehouse', 'code' => 'NETWORK-WAREHOUSE']);
        $parent = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Central', 'code' => 'CENTRAL', 'type' => 'warehouse', 'is_active' => true]);
        $child = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'parent_id' => $parent->id, 'name' => 'Store Bin', 'code' => 'STORE-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Network Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Network Each', 'status' => 1]);
        $category = Category::create(['name' => 'Network Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Network item', 'reorder_level' => 5, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $parent->id, 'reorder_point' => 5, 'min_stock' => 5, 'max_stock' => 10, 'is_active' => true]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $child->id, 'reorder_point' => 5, 'min_stock' => 10, 'max_stock' => 10, 'is_active' => true]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $parent->id, 'movement_type' => 'receipt', 'quantity' => 20, 'unit_cost' => 2, 'reason' => 'Network opening receipt']);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/replenishment/multi-echelon?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.shortage', 10)
            ->assertJsonPath('data.0.transfer_suggestion_quantity', 10)
            ->assertJsonPath('data.0.external_purchase_requirement', 0)
            ->assertJsonPath('data.0.nodes.1.parent_location_id', $parent->id)
            ->assertJsonPath('data.0.nodes.1.hierarchy.0.code', 'CENTRAL')
            ->assertJsonPath('data.0.nodes.1.hierarchy.1.code', 'STORE-BIN');
        $this->assertCount(0, app(ReplenishmentPlanningService::class)->proposalsForCompany($company->id, null, null, null, true));
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_demand_reorder_point_uses_location_issue_history_and_lead_time(): void
    {
        $company = Company::create(['name' => 'Demand Reorder Co', 'code' => 'DEMAND-REORDER']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Demand Branch', 'code' => 'DEMAND-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Demand Warehouse', 'code' => 'DEMAND-WAREHOUSE']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Demand Bin', 'code' => 'DEMAND-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Demand Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Demand Each', 'status' => 1]);
        $category = Category::create(['name' => 'Demand Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Demand item', 'reorder_level' => 0, 'quantity' => 0, 'status' => 1]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'reorder_point' => 0, 'reorder_point_method' => 'demand', 'reorder_history_days' => 10, 'lead_time_days' => 3, 'min_stock' => 6, 'is_active' => true]);
        for ($day = 0; $day < 10; $day++) {
            InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 1, 'posted_at' => now()->subDays($day)->setTime(10, 0)]);
        }
        $availability = Mockery::mock(InventoryAvailabilityService::class);
        $availability->shouldReceive('available')->once()->andReturn(0.0);
        $this->app->instance(InventoryAvailabilityService::class, $availability);
        $prices = Mockery::mock(SupplierProductPriceService::class);
        $prices->shouldReceive('bestFor')->once()->andReturnNull();
        $this->app->instance(SupplierProductPriceService::class, $prices);

        $proposal = app(ReplenishmentPlanningService::class)->proposalsForCompany($company->id)->first();

        $this->assertNotNull($proposal);
        $this->assertSame(6.0, (float) $proposal['quantity']);
        $this->assertSame(0.0, (float) $proposal['current_stock']);
    }
}
