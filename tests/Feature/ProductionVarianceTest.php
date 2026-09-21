<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOperation;
use App\Models\Routing;
use App\Models\RoutingOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionVarianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manufacturing_api_reports_yield_and_material_variance(): void
    {
        $company = Company::create(['name' => 'Variance Manufacturing Co', 'code' => 'VAR-MFG']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Variance Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Variance Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Finished variance item', 'sku' => 'VAR-FG', 'status' => 1, 'purchase_price' => 20]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Component variance item', 'sku' => 'VAR-COMP', 'status' => 1, 'purchase_price' => 2]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $finished->id, 'code' => 'BOM-VAR', 'name' => 'Variance BOM', 'output_quantity' => 1, 'is_active' => true]);
        $workCenter = WorkCenter::create(['company_id' => $company->id, 'code' => 'WC-VAR', 'name' => 'Variance Center', 'capacity_hours_per_day' => 8, 'labor_rate' => 10, 'machine_rate' => 5, 'is_active' => true]);
        $routing = Routing::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'code' => 'RT-VAR', 'name' => 'Variance Routing', 'is_active' => true]);
        $routingOperation = RoutingOperation::create(['company_id' => $company->id, 'routing_id' => $routing->id, 'work_center_id' => $workCenter->id, 'sequence' => 1, 'operation' => 'Assembly', 'setup_minutes' => 30, 'run_minutes' => 60]);
        $order = ProductionOrder::create([
            'company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $finished->id, 'planned_quantity' => 4, 'completed_quantity' => 2,
            'planned_date' => '2026-09-19', 'status' => 'in_progress', 'order_no' => 'MO-VAR-1',
            'bom_snapshot' => ['id' => $bom->id, 'output_quantity' => 1, 'lines' => [['component_product_id' => $component->id, 'quantity' => 3, 'scrap_percent' => 0, 'child' => null]], 'byproducts' => []],
        ]);
        ProductionOperation::create(['company_id' => $company->id, 'production_order_id' => $order->id, 'routing_operation_id' => $routingOperation->id, 'work_center_id' => $workCenter->id, 'sequence' => 1, 'operation' => 'Assembly', 'planned_quantity' => 4, 'completed_quantity' => 2, 'actual_setup_minutes' => 30, 'actual_run_minutes' => 120, 'status' => 'in_progress']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $component->id, 'movement_type' => 'issue', 'quantity' => 8, 'unit_cost' => 2.5, 'reference_type' => $order->getMorphClass(), 'reference_id' => $order->id]);

        Sanctum::actingAs($user, ['manufacturing:read']);
        $this->getJson('/api/manufacturing/production-variance?order_id='.$order->id)
            ->assertOk()
            ->assertJsonPath('data.0.completed_quantity', 2)
            ->assertJsonPath('data.0.yield_variance', -2)
            ->assertJsonPath('data.0.components.0.planned_quantity', 12)
            ->assertJsonPath('data.0.components.0.expected_to_date_quantity', 6)
            ->assertJsonPath('data.0.components.0.actual_quantity', 8)
            ->assertJsonPath('data.0.components.0.quantity_variance', 2)
            ->assertJsonPath('data.0.expected_operation_cost_to_date', 37.5)
            ->assertJsonPath('data.0.actual_operation_cost', 37.5)
            ->assertJsonPath('data.0.operation_cost_variance', 0)
            ->assertJsonPath('data.0.material_cost_variance', 8)
            ->assertJsonPath('data.0.total_cost_variance', 8)
            ->assertJsonPath('totals.order_count', 1);
    }
}
