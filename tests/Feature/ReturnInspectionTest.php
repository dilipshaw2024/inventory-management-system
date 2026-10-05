<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\InventoryReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_inspection_must_pass_before_approval(): void
    {
        $company = Company::create(['name' => 'Inspection Co', 'code' => 'RETURN-INSPECTION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $inspector = User::factory()->create(['company_id' => $company->id]);
        $return = InventoryReturn::create(['company_id' => $company->id, 'return_no' => 'RET-INSPECT-1', 'return_type' => 'sales', 'date' => now()->toDateString(), 'reason_code' => 'quality', 'inspection_required' => true, 'inspection_status' => 'pending', 'status' => 'pending', 'created_by' => $creator->id]);
        Sanctum::actingAs($inspector, ['sales:write']);

        $this->postJson('/api/integration/returns/'.$return->id.'/approve')->assertStatus(422)->assertJsonPath('message', 'This return must pass inspection before approval.');
        $this->postJson('/api/integration/returns/'.$return->id.'/inspect', ['inspection_status' => 'passed', 'inspection_notes' => 'Items inspected and accepted.'])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertDatabaseHas('inventory_returns', ['id' => $return->id, 'inspection_status' => 'passed', 'inspected_by' => $inspector->id]);
    }

    public function test_return_quality_plan_creates_linked_inspection_and_gates_return_approval(): void
    {
        $company = Company::create(['name' => 'Return Quality Co', 'code' => 'RETURN-QUALITY']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $inspector = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Return Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Return Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Return Each', 'code' => 'EA-RETURN-QUALITY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Return Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Return Quality Item', 'quantity' => 0, 'purchase_price' => 3, 'status' => 1]);
        Sanctum::actingAs($creator, ['sales:write', 'inventory:write', 'inventory:read']);

        $plan = $this->postJson('/api/inventory/quality/plans', [
            'code' => 'QC-RETURN-01', 'name' => 'Return inspection plan', 'inspection_type' => 'return', 'product_id' => $product->id,
            'lines' => [['sequence' => 1, 'characteristic' => 'Condition acceptable', 'data_type' => 'boolean']],
        ])->assertCreated();
        $return = $this->postJson('/api/integration/returns', [
            'return_type' => 'sales', 'customer_id' => $customer->id, 'date' => '2026-10-01', 'reason_code' => 'quality',
            'inspection_required' => true,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 3, 'quality_plan_id' => $plan->json('data.id')]],
        ])->assertCreated()->assertJsonPath('status', 'pending_approval');
        $returnId = $return->json('data.id');
        $inspectionId = $return->json('data.lines.0.quality_inspection_id');
        $this->assertNotNull($inspectionId);
        $this->assertDatabaseHas('quality_inspections', ['id' => $inspectionId, 'source_type' => 'inventory_return_line', 'source_id' => $return->json('data.lines.0.id')]);

        Sanctum::actingAs($approver, ['sales:write', 'inventory:write']);
        $this->postJson('/api/integration/returns/'.$returnId.'/approve')->assertStatus(422)->assertJsonPath('message', 'This return must pass inspection before approval.');

        Sanctum::actingAs($inspector, ['sales:write', 'inventory:write', 'inventory:read']);
        $lineId = $plan->json('data.lines.0.id');
        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/results', ['results' => [['plan_line_id' => $lineId, 'value_boolean' => true]]])->assertOk();
        $this->postJson('/api/inventory/quality/inspections/'.$inspectionId.'/complete', ['disposition' => 'release'])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertDatabaseHas('inventory_returns', ['id' => $returnId, 'inspection_status' => 'passed']);
        $this->postJson('/api/integration/returns/'.$returnId.'/inspect', ['inspection_status' => 'passed', 'inspection_notes' => 'Legacy override'])->assertStatus(422);

        Sanctum::actingAs($approver, ['sales:write', 'inventory:write']);
        $this->postJson('/api/integration/returns/'.$returnId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
    }

}
