<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Category;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Models\Product;
use App\Models\Routing;
use App\Models\RoutingOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\WorkCenter;
use App\Services\ProductionSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_are_persisted_in_sequence_against_work_center_capacity(): void
    {
        $company = Company::create(['name' => 'Scheduling Co', 'code' => 'SCHED-TEST']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Scheduling Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Finished good', 'quantity' => 0, 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Component', 'quantity' => 10, 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-SCHED', 'name' => 'Scheduling BOM', 'output_quantity' => 1, 'is_active' => true]);
        $bom->lines()->create(['component_product_id' => $component->id, 'quantity' => 1]);
        $center = WorkCenter::create(['company_id' => $company->id, 'code' => 'WC-SCHED', 'name' => 'Scheduling Center', 'capacity_hours_per_day' => 8, 'is_active' => true]);
        $routing = Routing::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'code' => 'RT-SCHED', 'name' => 'Scheduling Routing', 'is_active' => true]);
        $routingOperation = RoutingOperation::create(['company_id' => $company->id, 'routing_id' => $routing->id, 'work_center_id' => $center->id, 'sequence' => 1, 'operation' => 'Assemble', 'setup_minutes' => 30, 'run_minutes' => 60]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 2, 'planned_date' => '2026-09-18', 'status' => 'draft', 'order_no' => 'MO-SCHED-TEST']);

        $scheduled = app(ProductionSchedulingService::class)->schedule($order, '2026-09-18 16:00:00');

        $this->assertSame('scheduled', $scheduled->operations->first()->schedule_status);
        $this->assertSame('2026-09-21 08:00', $scheduled->operations->first()->scheduled_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-21 10:30', $scheduled->operations->first()->scheduled_end_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('production_operations', ['production_order_id' => $order->id, 'routing_operation_id' => $routingOperation->id, 'schedule_status' => 'scheduled']);
    }
}
