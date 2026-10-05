<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QualityInspection;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QualityInspectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_plan_and_inspection_lifecycle_is_idempotent_and_evaluates_results(): void
    {
        $company = Company::create(['name' => 'Quality Co', 'code' => 'QUALITY-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Quality Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Quality Each', 'code' => 'EA-QUALITY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Quality Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Quality Item', 'quantity' => 10, 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $planPayload = [
            'code' => 'QC-INCOMING-01',
            'name' => 'Incoming quality plan',
            'inspection_type' => 'receiving',
            'product_id' => $product->id,
            'sampling_percent' => 40,
            'external_reference' => 'PLAN-QUALITY-1',
            'lines' => [
                ['sequence' => 1, 'characteristic' => 'Length', 'data_type' => 'numeric', 'unit' => 'mm', 'minimum_value' => 9.5, 'maximum_value' => 10.5],
                ['sequence' => 2, 'characteristic' => 'Packaging intact', 'data_type' => 'boolean'],
            ],
        ];
        $createdPlan = $this->postJson('/api/inventory/quality/plans', $planPayload)
            ->assertCreated()
            ->assertJsonPath('status', 'created');
        $planId = $createdPlan->json('data.id');
        $this->postJson('/api/inventory/quality/plans', $planPayload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_ignored')
            ->assertJsonPath('data.id', $planId);

        $createdInspection = $this->postJson('/api/inventory/quality/inspections', [
            'plan_id' => $planId,
            'product_id' => $product->id,
            'quantity' => 5,
            'external_reference' => 'INSPECTION-QUALITY-1',
            'source_type' => 'goods_receipt',
            'source_id' => 1001,
        ])->assertCreated()->assertJsonPath('status', 'created');
        $inspectionId = $createdInspection->json('data.id');
        $this->assertSame('2.00000000', (string) $createdInspection->json('data.sample_quantity'));
        $this->assertDatabaseHas('quality_inspections', ['id' => $inspectionId, 'quantity' => 5, 'sample_quantity' => 2]);
        $this->patchJson('/api/inventory/quality/plans/'.$planId, ['sampling_percent' => 50])
            ->assertStatus(422);

        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/results', [
            'results' => [
                ['plan_line_id' => $createdPlan->json('data.lines.0.id'), 'value_numeric' => 10],
                ['plan_line_id' => $createdPlan->json('data.lines.1.id'), 'value_boolean' => true],
            ],
        ])->assertOk()->assertJsonPath('status', 'in_progress');

        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/complete', ['disposition' => 'release'])
            ->assertOk()->assertJsonPath('status', 'passed')
            ->assertJsonPath('data.disposition', 'release');
        $this->patchJson('/api/inventory/quality/plans/'.$planId, ['sampling_percent' => 50, 'is_active' => false])
            ->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('quality_inspections', ['id' => $inspectionId, 'status' => 'passed']);
        $this->assertDatabaseHas('quality_inspection_results', ['inspection_id' => $inspectionId, 'status' => 'passed']);
        $this->getJson('/api/inventory/quality/inspections?status=passed')
            ->assertOk()->assertJsonPath('data.0.id', $inspectionId);
    }

    public function test_failed_quality_result_cannot_be_released_and_is_tenant_scoped(): void
    {
        $company = Company::create(['name' => 'Quality Failure Co', 'code' => 'QUALITY-FAIL']);
        $otherCompany = Company::create(['name' => 'Other Quality Co', 'code' => 'QUALITY-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Failure Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Failure Each', 'code' => 'EA-QUALITY-FAIL', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Failure Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Failure Item', 'quantity' => 2, 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write', 'inventory:read']);

        $plan = $this->postJson('/api/inventory/quality/plans', [
            'code' => 'QC-FAIL-01', 'name' => 'Failure plan', 'inspection_type' => 'final', 'product_id' => $product->id,
            'lines' => [['sequence' => 1, 'characteristic' => 'Weight', 'data_type' => 'numeric', 'minimum_value' => 5, 'maximum_value' => 6]],
        ])->assertCreated();
        $inspection = $this->postJson('/api/inventory/quality/inspections', ['plan_id' => $plan->json('data.id'), 'product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $id = $inspection->json('data.id');
        $lineId = $plan->json('data.lines.0.id');

        $this->postJson('/api/inventory/quality/inspections/'.$id.'/results', ['results' => [['plan_line_id' => $lineId, 'value_numeric' => 9]]])->assertOk();
        $this->postJson('/api/inventory/quality/inspections/'.$id.'/complete', ['disposition' => 'release'])
            ->assertStatus(422);
        $this->postJson('/api/inventory/quality/inspections/'.$id.'/complete', ['disposition' => 'quarantine'])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->postJson('/api/inventory/quality/inspections/'.$id.'/apply-disposition')
            ->assertOk()->assertJsonPath('status', 'applied')
            ->assertJsonPath('data.inventory_status_transfer.to_status', 'quarantine');
        $this->assertDatabaseHas('inventory_status_transfers', ['company_id' => $company->id, 'to_status' => 'quarantine', 'status' => 'approved']);
        $this->assertDatabaseHas('inventory_status_balances', ['product_id' => $product->id, 'status' => 'quarantine', 'quantity' => 1]);
        $this->postJson('/api/inventory/quality/inspections/'.$id.'/apply-disposition')
            ->assertOk()->assertJsonPath('status', 'already_applied');

        Sanctum::actingAs($otherUser, ['inventory:read']);
        $this->getJson('/api/inventory/quality/inspections')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, QualityInspection::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_warehouse_scoped_user_cannot_create_quality_inspection_for_another_warehouse(): void
    {
        $company = Company::create(['name' => 'Quality Warehouse Scope Co', 'code' => 'QUALITY-WH-SCOPE']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Quality Branch', 'code' => 'QUALITY-BRANCH']);
        $assignedWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Assigned Warehouse', 'code' => 'QUALITY-WH-A']);
        $otherWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Other Warehouse', 'code' => 'QUALITY-WH-B']);
        $otherLocation = InventoryLocation::create(['warehouse_id' => $otherWarehouse->id, 'name' => 'Other Bin', 'code' => 'QUALITY-BIN-B', 'type' => 'bin', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'warehouse_id' => $assignedWarehouse->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Scope Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Scope Each', 'code' => 'EA-QUALITY-SCOPE', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Scope Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Scoped Quality Item', 'quantity' => 1, 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $plan = $this->postJson('/api/inventory/quality/plans', [
            'code' => 'QC-SCOPE-01', 'name' => 'Warehouse scope plan', 'inspection_type' => 'receiving', 'product_id' => $product->id,
            'lines' => [['sequence' => 1, 'characteristic' => 'Visual', 'data_type' => 'boolean']],
        ])->assertCreated();

        $this->postJson('/api/inventory/quality/inspections', [
            'plan_id' => $plan->json('data.id'), 'product_id' => $product->id, 'location_id' => $otherLocation->id, 'quantity' => 1,
        ])->assertNotFound();
    }


    public function test_receiving_quality_plan_creates_linked_inspection_and_gates_receipt_approval(): void
    {
        $company = Company::create(['name' => 'Receiving Quality Co', 'code' => 'QUALITY-RECEIVING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Receiving Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Receiving Each', 'code' => 'EA-QUALITY-RECEIVING', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Receiving Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Receiving Item', 'quantity' => 0, 'status' => 1]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-QUALITY-RECEIVING', 'date' => '2026-10-01', 'status' => 'approved']);
        $orderLine = PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'ordered_qty' => 2, 'received_qty' => 0, 'unit_price' => 10]);
        Sanctum::actingAs($user, ['purchasing:write', 'inventory:read', 'inventory:write']);

        $plan = $this->postJson('/api/inventory/quality/plans', [
            'code' => 'QC-RECEIVING-01', 'name' => 'Receiving inspection plan', 'inspection_type' => 'receiving', 'sampling_percent' => 40, 'product_id' => $product->id,
            'lines' => [['sequence' => 1, 'characteristic' => 'Packaging intact', 'data_type' => 'boolean']],
        ])->assertCreated();

        $receipt = $this->postJson('/api/integration/goods-receipts', [
            'purchase_order_id' => $order->id, 'date' => '2026-10-01', 'inspection_required' => true,
            'lines' => [['purchase_order_line_id' => $orderLine->id, 'quality_plan_id' => $plan->json('data.id'), 'quantity' => 2, 'unit_cost' => 10]],
        ])->assertCreated()->assertJsonPath('data.inspection_status', 'pending');
        $inspectionId = $receipt->json('data.lines.0.quality_inspection_id');
        $this->assertNotNull($inspectionId);
        $this->assertDatabaseHas('quality_inspections', ['id' => $inspectionId, 'source_type' => 'goods_receipt_line', 'source_id' => $receipt->json('data.lines.0.id')]);
        $this->assertDatabaseHas('quality_inspections', ['id' => $inspectionId, 'quantity' => 2, 'sample_quantity' => 1]);

        Sanctum::actingAs($approver, ['purchasing:write', 'inventory:read', 'inventory:write']);
        $this->postJson('/api/integration/goods-receipts/'.$receipt->json('data.id').'/approve')->assertStatus(422);

        Sanctum::actingAs($user, ['purchasing:write', 'inventory:read', 'inventory:write']);
        $lineId = $plan->json('data.lines.0.id');
        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/results', ['results' => [['plan_line_id' => $lineId, 'value_boolean' => true]]])->assertOk();
        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/complete', ['disposition' => 'release'])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertDatabaseHas('goods_receipts', ['id' => $receipt->json('data.id'), 'inspection_status' => 'passed']);

        Sanctum::actingAs($approver, ['purchasing:write', 'inventory:read', 'inventory:write']);
        $this->postJson('/api/integration/goods-receipts/'.$receipt->json('data.id').'/approve')->assertOk()->assertJsonPath('status', 'approved');
    }

}
