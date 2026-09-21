<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use App\Models\Unit;
use App\Services\BomExplosionService;
use App\Services\InventoryAvailabilityService;
use App\Services\MrpPlanningService;
use App\Services\SupplierProductPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MrpPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_mrp_reserves_safety_stock_and_exposes_buy_timing(): void
    {
        $company = Company::create(['name' => 'MRP Planning Co', 'code' => 'MRP-PLAN']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'MRP Branch', 'code' => 'MRP-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'MRP Warehouse', 'code' => 'MRP-WAREHOUSE']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'MRP Bin', 'code' => 'MRP-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'MRP Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'MRP Each', 'status' => 1]);
        $category = Category::create(['name' => 'MRP Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'MRP Finished', 'max_stock' => 1, 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'MRP Component', 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $finished->id, 'code' => 'BOM-MRP-1', 'name' => 'MRP BOM', 'output_quantity' => 1, 'is_active' => true, 'approval_status' => 'approved']);
        $bom->lines()->create(['company_id' => $company->id, 'component_product_id' => $component->id, 'quantity' => 1, 'scrap_percent' => 0]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $component->id, 'location_id' => $location->id, 'reorder_point' => 0, 'safety_stock' => 2, 'lead_time_days' => 4, 'safety_time_days' => 1, 'is_active' => true]);

        $availability = Mockery::mock(InventoryAvailabilityService::class);
        $availability->shouldReceive('available')->andReturn(0.0);
        $this->app->instance(InventoryAvailabilityService::class, $availability);
        $explosion = Mockery::mock(BomExplosionService::class);
        $explosion->shouldReceive('leafRequirements')->once()->andReturn([$component->id => 5.0]);
        $this->app->instance(BomExplosionService::class, $explosion);
        $prices = Mockery::mock(SupplierProductPriceService::class);
        $prices->shouldReceive('planningFor')->once()->andReturn(new SupplierProductPrice(['minimum_quantity' => 10, 'lead_time_days' => 4]));
        $this->app->instance(SupplierProductPriceService::class, $prices);

        $proposal = app(MrpPlanningService::class)->proposalsForCompany($company->id)['proposals']->first();

        $this->assertNotNull($proposal);
        $this->assertSame($component->id, $proposal['product_id']);
        $this->assertSame(2.0, (float) $proposal['safety_stock']);
        $this->assertSame(7.0, (float) $proposal['shortage']);
        $this->assertSame(10.0, (float) $proposal['minimum_order_quantity']);
        $this->assertSame(10.0, (float) $proposal['planned_order_quantity']);
        $this->assertSame('purchase', $proposal['supply_type']);
        $this->assertSame(4, $proposal['lead_time_days']);
        $this->assertSame(1, $proposal['safety_time_days']);
        $this->assertSame(5, $proposal['planning_days']);
        $this->assertSame(now()->addDays(5)->toDateString(), $proposal['required_by']);
    }
}
