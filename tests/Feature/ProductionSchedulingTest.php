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
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\BomExplosionService;
use App\Services\ProductionSchedulingService;
use App\Services\ProductionService;
use App\Services\ErpSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_bom_component_uom_is_normalized_and_snapshotted_as_stock_quantity(): void
    {
        $company = Company::create(['name' => 'Manufacturing UOM Co', 'code' => 'MFG-UOM']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'UOM Supplier', 'is_active' => true]);
        $each = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA', 'dimension' => 'unit', 'status' => 1]);
        $box = Unit::create(['company_id' => $company->id, 'name' => 'Box', 'code' => 'BOX', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['name' => 'UOM Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $each->id, 'category_id' => $category->id, 'name' => 'UOM Finished good', 'quantity' => 0, 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $each->id, 'category_id' => $category->id, 'name' => 'UOM Component', 'quantity' => 100, 'status' => 1]);
        UnitConversion::create(['company_id' => $company->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => 12, 'is_active' => true, 'effective_from' => '2026-01-01']);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-UOM', 'name' => 'UOM BOM', 'output_quantity' => 1, 'is_active' => true]);
        $bom->lines()->create(['component_product_id' => $component->id, 'uom_id' => $box->id, 'quantity' => 2]);

        $service = app(BomExplosionService::class);
        $this->assertSame(24.0, $service->leafRequirements($bom, 1, $company->id, '2026-09-17')[$component->id]);
        $snapshot = $service->snapshot($bom, $company->id, '2026-09-17');
        $this->assertSame(24.0, $snapshot['lines'][0]['quantity']);
        $this->assertSame(2.0, $snapshot['lines'][0]['entered_quantity']);
        $this->assertSame($box->id, $snapshot['lines'][0]['uom_id']);
        $this->assertSame(24.0, $service->leafRequirementsFromSnapshot($snapshot, 1)[$component->id]);
    }

    public function test_batch_scheduler_sequences_orders_on_shared_work_center(): void
    {
        $company = Company::create(['name' => 'Batch Scheduling Co', 'code' => 'BATCH-SCHED']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Batch Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Batch Each', 'status' => 1]);
        $category = Category::create(['name' => 'Batch Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Batch Finished', 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-BATCH', 'name' => 'Batch BOM', 'output_quantity' => 1, 'is_active' => true]);
        $center = WorkCenter::create(['company_id' => $company->id, 'code' => 'WC-BATCH', 'name' => 'Batch Center', 'capacity_hours_per_day' => 8, 'is_active' => true]);
        $routing = Routing::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'code' => 'RT-BATCH', 'name' => 'Batch Routing', 'is_active' => true]);
        $routingOperation = RoutingOperation::create(['company_id' => $company->id, 'routing_id' => $routing->id, 'work_center_id' => $center->id, 'sequence' => 1, 'operation' => 'Batch operation', 'setup_minutes' => 0, 'run_minutes' => 60]);
        $first = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-18', 'status' => 'draft', 'order_no' => 'MO-BATCH-1']);
        $second = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-18', 'status' => 'draft', 'order_no' => 'MO-BATCH-2']);

        $scheduled = app(ProductionSchedulingService::class)->scheduleMany([$second, $first], '2026-09-18 08:00:00');
        $firstOperation = $scheduled->firstWhere('id', $first->id)->operations->first();
        $secondOperation = $scheduled->firstWhere('id', $second->id)->operations->first();
        $this->assertSame($first->id, $scheduled->first()->id);
        $this->assertSame('2026-09-18 08:00', $firstOperation->scheduled_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-18 09:00', $firstOperation->scheduled_end_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-18 09:00', $secondOperation->scheduled_start_at->format('Y-m-d H:i'));
        $this->assertSame('scheduled', $secondOperation->schedule_status);
    }

    public function test_batch_scheduler_supports_finite_dispatch_rules(): void
    {
        $company = Company::create(['name' => 'Dispatch Rule Co', 'code' => 'DISPATCH-RULE']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Dispatch Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dispatch Each', 'status' => 1]);
        $category = Category::create(['name' => 'Dispatch Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Dispatch Finished', 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-DISPATCH', 'name' => 'Dispatch BOM', 'output_quantity' => 1, 'is_active' => true]);
        $center = WorkCenter::create(['company_id' => $company->id, 'code' => 'WC-DISPATCH', 'name' => 'Dispatch Center', 'capacity_hours_per_day' => 8, 'is_active' => true]);
        $routing = Routing::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'code' => 'RT-DISPATCH', 'name' => 'Dispatch Routing', 'is_active' => true]);
        RoutingOperation::create(['company_id' => $company->id, 'routing_id' => $routing->id, 'work_center_id' => $center->id, 'sequence' => 1, 'operation' => 'Dispatch operation', 'setup_minutes' => 0, 'run_minutes' => 60]);
        $long = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 4, 'planned_date' => '2026-09-19', 'status' => 'draft', 'order_no' => 'MO-DISPATCH-LONG']);
        $short = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-18', 'status' => 'draft', 'order_no' => 'MO-DISPATCH-SHORT']);

        $scheduled = app(ProductionSchedulingService::class)->scheduleMany([$long, $short], '2026-09-18 08:00:00', 'shortest_processing_time');

        $this->assertSame($short->id, $scheduled->first()->id);
        $this->assertSame('2026-09-18 08:00', $short->fresh()->operations->first()->scheduled_start_at->format('Y-m-d H:i'));
    }

    public function test_production_order_pause_and_resume_preserve_lifecycle_state(): void
    {
        $company = Company::create(['name' => 'Pause Co', 'code' => 'PAUSE-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Pause Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-P', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['name' => 'Pause Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Pause Finished good', 'quantity' => 0, 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-PAUSE', 'name' => 'Pause BOM', 'output_quantity' => 1, 'is_active' => true]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 2, 'planned_date' => '2026-09-18', 'status' => 'in_progress', 'order_no' => 'MO-PAUSE-TEST']);

        $service = app(ProductionService::class);
        $paused = $service->pause($order->id, 'Machine maintenance');
        $this->assertSame('paused', $paused->status);
        $this->assertSame('in_progress', $paused->paused_from_status);
        $this->assertSame('Machine maintenance', $paused->pause_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'production_order.paused', 'auditable_id' => $order->id]);

        $resumed = $service->resume($order->id);
        $this->assertSame('in_progress', $resumed->status);
        $this->assertNull($resumed->paused_from_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'production_order.resumed', 'auditable_id' => $order->id]);

        $order->update(['status' => 'completed']);
        $closed = $service->close($order->id, 'Production and costing reconciled');
        $this->assertSame('closed', $closed->status);
        $this->assertSame('Production and costing reconciled', $closed->close_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'production_order.closed', 'auditable_id' => $order->id]);
    }

    public function test_bom_revision_requires_approval_before_production_use(): void
    {
        $company = Company::create(['name' => 'BOM Approval Co', 'code' => 'BOM-APPROVAL']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'BOM Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-A', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'BOM Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Approved finished good', 'quantity' => 0, 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Approved component', 'quantity' => 10, 'status' => 1]);

        Sanctum::actingAs($creator, ['manufacturing:write', 'manufacturing:read']);
        $created = $this->postJson('/api/manufacturing/boms', ['product_id' => $finished->id, 'code' => 'BOM-APPROVAL', 'name' => 'Approval BOM', 'output_quantity' => 1, 'external_reference' => 'BOM-REPLAY-1', 'lines' => [['component_product_id' => $component->id, 'quantity' => 1]]]);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $bom = BillOfMaterial::where('code', 'BOM-APPROVAL')->firstOrFail();
        $this->postJson('/api/manufacturing/boms', ['product_id' => $finished->id, 'code' => 'BOM-REPLAYED', 'name' => 'Changed payload', 'output_quantity' => 99, 'external_reference' => 'BOM-REPLAY-1', 'lines' => [['component_product_id' => $component->id, 'quantity' => 99]]])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $bom->id);
        $this->postJson('/api/manufacturing/orders', ['bom_id' => $bom->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-18'])->assertStatus(422);

        Sanctum::actingAs($checker, ['manufacturing:write', 'manufacturing:read']);
        $this->postJson('/api/manufacturing/boms/'.$bom->id.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/manufacturing/orders', ['bom_id' => $bom->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-18'])->assertCreated();
        $createdOrder = $this->postJson('/api/manufacturing/orders', [
            'bom_id' => $bom->id, 'planned_quantity' => 2, 'planned_date' => '2026-09-18',
            'external_reference' => 'PRODUCTION-ORDER-REPLAY-1',
        ])->assertCreated()->assertJsonPath('status', 'pending_approval');
        $this->postJson('/api/manufacturing/orders', [
            'bom_id' => $bom->id, 'planned_quantity' => 99, 'planned_date' => '2026-09-18',
            'external_reference' => 'PRODUCTION-ORDER-REPLAY-1',
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored')
            ->assertJsonPath('data.id', $createdOrder->json('data.id'));

        $revision = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $finished->id, 'code' => 'BOM-APPROVAL-V2', 'version' => '2', 'name' => 'Approval BOM V2', 'output_quantity' => 2, 'is_active' => true]);
        $revision->lines()->create(['component_product_id' => $component->id, 'quantity' => 3]);
        $comparison = $this->getJson('/api/manufacturing/boms/'.$bom->id.'/compare/'.$revision->id);
        $comparison->assertOk()->assertJsonPath('data.header_changes.version.to', '2')->assertJsonPath('data.component_changes.0.type', 'changed');
    }

    public function test_production_scrap_is_approved_and_posted_to_the_ledger(): void
    {
        $company = Company::create(['name' => 'Scrap Co', 'code' => 'SCRAP-TEST']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Scrap Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-S', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Scrap Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Scrap Component', 'quantity' => 10, 'status' => 1]);
        $recovery = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Recovered Material', 'quantity' => 0, 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-SCRAP', 'name' => 'Scrap BOM', 'output_quantity' => 1, 'is_active' => true]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 1, 'planned_date' => '2026-09-17', 'status' => 'in_progress', 'order_no' => 'MO-SCRAP-TEST']);

        Sanctum::actingAs($creator, ['manufacturing:write', 'manufacturing:read']);
        $created = $this->postJson('/api/manufacturing/production-scrap', ['production_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'reason' => 'Damaged during setup', 'external_reference' => 'SCRAP-EXT-1', 'recovery_product_id' => $recovery->id, 'recovery_quantity' => 2, 'recovery_unit_cost' => 2]);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $scrapId = $created->json('data.id');
        $this->postJson('/api/manufacturing/production-scrap', ['production_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 99, 'reason' => 'Changed replay payload', 'external_reference' => 'SCRAP-EXT-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $scrapId);

        Sanctum::actingAs($checker, ['manufacturing:write', 'manufacturing:read']);
        $this->postJson('/api/manufacturing/production-scrap/'.$scrapId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('production_scrap_records', ['id' => $scrapId, 'status' => 'approved']);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'movement_type' => 'scrap', 'quantity' => 3]);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $recovery->id, 'movement_type' => 'receipt', 'quantity' => 2]);
        $this->assertSame(7.0, (float) $product->fresh()->quantity);
        $this->assertSame(2.0, (float) $recovery->fresh()->quantity);
        $this->getJson('/api/manufacturing/production-scrap/summary?status=approved')->assertOk()->assertJsonPath('totals.quantity', 3)->assertJsonPath('totals.scrap_value', 0)->assertJsonPath('totals.recovery_value', 4)->assertJsonPath('totals.net_loss', -4);
    }

    public function test_scheduled_production_release_requires_company_setting_and_supports_dry_run(): void
    {
        $company = Company::create(['name' => 'Auto Release Co', 'code' => 'AUTO-RELEASE']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Auto Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Auto Each', 'status' => 1]);
        $category = Category::create(['name' => 'Auto Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Auto finished good', 'quantity' => 0, 'status' => 1]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'BOM-AUTO', 'name' => 'Auto BOM', 'output_quantity' => 1, 'is_active' => true, 'approval_status' => 'approved']);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $product->id, 'planned_quantity' => 1, 'planned_date' => now()->toDateString(), 'status' => 'draft', 'order_no' => 'MO-AUTO-1']);

        $this->artisan('erp:planning:auto-release-production-orders', ['--company' => $company->id])->assertExitCode(0);
        $this->assertSame('draft', $order->fresh()->status);
        app(ErpSettingService::class)->put('auto_release_production_orders', true, 'bool', $company->id);
        $this->artisan('erp:planning:auto-release-production-orders', ['--company' => $company->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame('draft', $order->fresh()->status);
    }
}
