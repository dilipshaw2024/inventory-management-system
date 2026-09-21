<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseRequisitionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_can_be_created_approved_converted_and_replayed_through_integration_api(): void
    {
        $company = Company::create(['name' => 'Requisition Integration Co', 'code' => 'REQ-INTEGRATION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Requisition Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Requisition Each', 'code' => 'REQ-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Requisition Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Requisition Product', 'quantity' => 0, 'status' => 1]);
        Sanctum::actingAs($creator, ['purchasing:read', 'purchasing:write']);

        $created = $this->postJson('/api/integration/requisitions', [
            'external_reference' => 'REQ-EXT-1', 'requested_date' => now()->toDateString(), 'required_date' => now()->addDays(7)->toDateString(),
            'suggested_supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'requested_qty' => 3, 'estimated_unit_price' => 15]],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('data.lines.0.product_id', $product->id);
        $requisitionId = $created->json('data.id');

        $duplicate = $this->postJson('/api/integration/requisitions', [
            'external_reference' => 'REQ-EXT-1', 'requested_date' => now()->toDateString(), 'suggested_supplier_id' => $supplier->id,
            'lines' => [['product_id' => $product->id, 'requested_qty' => 3, 'estimated_unit_price' => 15]],
        ]);
        $duplicate->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $requisitionId);
        $this->getJson('/api/integration/requisitions?status=submitted')->assertOk()->assertJsonPath('data.0.id', $requisitionId);

        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $this->postJson('/api/integration/requisitions/'.$requisitionId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $converted = $this->postJson('/api/integration/requisitions/'.$requisitionId.'/convert');
        $converted->assertCreated()->assertJsonPath('status', 'purchase_order_created')->assertJsonPath('data.supplier_id', $supplier->id)->assertJsonPath('data.lines.0.ordered_qty', '3.000000');
        $this->assertDatabaseHas('purchase_requisitions', ['id' => $requisitionId, 'status' => 'converted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_requisition.converted']);
    }
}
