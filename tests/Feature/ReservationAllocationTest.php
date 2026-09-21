<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\MaintenanceOrder;
use App\Models\Product;
use App\Models\ServiceAsset;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_plan_splits_demand_across_locations_after_reservations(): void
    {
        $company = Company::create(['name' => 'Allocation Co', 'code' => 'ALLOCATION-CO']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Allocation Branch', 'code' => 'ALLOC-BRANCH', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Allocation Warehouse', 'code' => 'ALLOC-WH', 'is_active' => true]);
        $first = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'First Bin', 'code' => 'ALLOC-A', 'type' => 'bin', 'is_active' => true]);
        $second = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Second Bin', 'code' => 'ALLOC-B', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Allocation Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Allocation Each', 'status' => 1]);
        $category = Category::create(['name' => 'Allocation Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Allocatable item', 'sku' => 'ALLOC-001', 'quantity' => 0, 'status' => 1]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $first->id, 'movement_type' => 'receipt', 'quantity' => 3, 'unit_cost' => 10]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $second->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 10]);
        StockReservation::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $second->id, 'quantity' => 2, 'released_quantity' => 0, 'status' => 'active']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['inventory:read']);

        $response = $this->getJson('/api/integration/reservations/allocation-plan?product_id='.$product->id.'&quantity=5&location_ids[]='.$first->id.'&location_ids[]='.$second->id);

        $response->assertOk()->assertJsonPath('status', 'planned')->assertJsonPath('data.allocated_quantity', 5)->assertJsonPath('data.shortfall', 0)->assertJsonPath('data.allocations.0.location_id', $first->id)->assertJsonPath('data.allocations.0.allocated_quantity', 3)->assertJsonPath('data.allocations.1.location_id', $second->id)->assertJsonPath('data.allocations.1.allocated_quantity', 2);
        $this->assertSame(1, StockReservation::where('product_id', $product->id)->count());
    }

    public function test_maintenance_part_auto_allocation_creates_location_reservations_from_the_plan(): void
    {
        $company = Company::create(['name' => 'Service Allocation Co', 'code' => 'SERVICE-ALLOC-CO']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Service Branch', 'code' => 'SERVICE-BRANCH', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Service Warehouse', 'code' => 'SERVICE-WH', 'is_active' => true]);
        $first = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Service Bin A', 'code' => 'SERVICE-A', 'type' => 'bin', 'is_active' => true]);
        $second = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Service Bin B', 'code' => 'SERVICE-B', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Service Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Service Each', 'status' => 1]);
        $category = Category::create(['name' => 'Service Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Service part', 'sku' => 'SERVICE-ALLOC-001', 'quantity' => 0, 'status' => 1]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $first->id, 'movement_type' => 'receipt', 'quantity' => 3, 'unit_cost' => 10]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $second->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 10]);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'SERVICE-ASSET-001', 'name' => 'Service asset', 'status' => 'active']);
        $order = MaintenanceOrder::create(['company_id' => $company->id, 'order_no' => 'SERVICE-MO-001', 'asset_id' => $asset->id, 'maintenance_type' => 'corrective', 'status' => 'planned']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['service:write']);

        $response = $this->postJson('/api/service/orders/'.$order->id.'/parts/allocate', [
            'product_id' => $product->id, 'quantity' => 5, 'location_ids' => [$first->id, $second->id], 'strategy' => 'fefo',
        ]);

        $response->assertCreated()->assertJsonPath('status', 'reserved')->assertJsonPath('data.plan.allocated_quantity', 5)->assertJsonPath('data.plan.shortfall', 0);
        $this->assertSame(2, StockReservation::where('source_type', MaintenanceOrder::class)->where('source_id', $order->id)->count());
        $this->assertDatabaseHas('stock_reservations', ['source_id' => $order->id, 'location_id' => $second->id, 'quantity' => 2]);
    }
}
