<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Category;
use App\Models\Company;
use App\Models\ProductionOperation;
use App\Models\ProductionOrder;
use App\Models\InventoryMovement;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostConsumption;
use App\Models\Product;
use App\Models\Routing;
use App\Models\RoutingOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WipValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wip_feed_reports_operation_level_remaining_cost(): void
    {
        $company = Company::create(['name' => 'WIP Valuation Co', 'code' => 'WIP-VAL']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'WIP Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'WIP Each', 'status' => 1]);
        $category = Category::create(['name' => 'WIP Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'WIP Finished', 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-WIP', 'name' => 'WIP BOM', 'output_quantity' => 1, 'is_active' => true]);
        $center = WorkCenter::create(['company_id' => $company->id, 'code' => 'WC-WIP', 'name' => 'WIP Center', 'capacity_hours_per_day' => 8, 'labor_rate' => 10, 'machine_rate' => 5, 'is_active' => true]);
        $routing = Routing::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'code' => 'RT-WIP', 'name' => 'WIP Routing', 'is_active' => true]);
        $routingOperation = RoutingOperation::create(['company_id' => $company->id, 'routing_id' => $routing->id, 'work_center_id' => $center->id, 'sequence' => 1, 'operation' => 'Assembly', 'setup_minutes' => 30, 'run_minutes' => 60]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 2, 'completed_quantity' => 0, 'planned_date' => '2026-09-18', 'status' => 'in_progress', 'order_no' => 'MO-WIP-1']);
        $operation = ProductionOperation::create(['company_id' => $company->id, 'production_order_id' => $order->id, 'routing_operation_id' => $routingOperation->id, 'work_center_id' => $center->id, 'sequence' => 1, 'operation' => 'Assembly', 'planned_quantity' => 2, 'completed_quantity' => 1, 'actual_setup_minutes' => 30, 'actual_run_minutes' => 60, 'status' => 'in_progress']);
        $movement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 20, 'reference_type' => $order->getMorphClass(), 'reference_id' => $order->id, 'posted_at' => '2026-09-18 10:00:00']);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 2, 'remaining_quantity' => 0, 'unit_cost' => 20, 'source_type' => $order->getMorphClass(), 'source_id' => $order->id, 'received_at' => '2026-09-18 09:00:00']);
        InventoryCostConsumption::create(['cost_layer_id' => $layer->id, 'product_id' => $product->id, 'movement_id' => $movement->id, 'quantity' => 2, 'unit_cost' => 20, 'total_cost' => 40, 'costing_method' => 'fifo']);

        Sanctum::actingAs($user, ['manufacturing:read']);
        $this->getJson('/api/manufacturing/wip')
            ->assertOk()->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.operations.0.remaining_quantity', 1)
            ->assertJsonPath('data.0.operations.0.estimated_remaining_cost', 22.5)
            ->assertJsonPath('data.0.estimated_remaining_operation_cost', 22.5)
            ->assertJsonPath('data.0.material_cost_source', 'cost_layers')
            ->assertJsonPath('data.0.material_cost_to_date', 40)
            ->assertJsonPath('data.0.wip_value', 62.5)
            ->assertJsonPath('totals.estimated_remaining_operation_cost', 22.5);
    }
}
