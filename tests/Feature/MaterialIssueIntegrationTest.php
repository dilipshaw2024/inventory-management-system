<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\BillOfMaterial;
use App\Models\BomLine;
use App\Models\Company;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentLine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialIssueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manufacturing_material_issue_requires_an_effective_bom_component_and_reports_variance(): void
    {
        $company = Company::create(['name' => 'Material Issue Co', 'code' => 'MAT-ISSUE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Material Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Material Each', 'code' => 'EA-MAT-ISSUE', 'status' => 1]);
        $category = Category::create(['name' => 'Material Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Finished Material Item', 'sku' => 'MAT-FG', 'status' => 1, 'is_stock_item' => true]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'BOM Component', 'sku' => 'MAT-COMP', 'status' => 1, 'is_stock_item' => true]);
        $unrelated = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Unrelated Item', 'sku' => 'MAT-OTHER', 'status' => 1, 'is_stock_item' => true]);
        $bom = BillOfMaterial::create(['product_id' => $finished->id, 'code' => 'BOM-MAT-1', 'name' => 'Material Test BOM', 'output_quantity' => 1, 'is_active' => true, 'effective_from' => '2026-01-01']);
        BomLine::create(['bom_id' => $bom->id, 'component_product_id' => $component->id, 'quantity' => 2, 'scrap_percent' => 0]);
        $order = ProductionOrder::create([
            'company_id' => $company->id, 'order_no' => 'MO-MAT-1', 'bom_id' => $bom->id, 'product_id' => $finished->id,
            'planned_quantity' => 2, 'completed_quantity' => 0, 'planned_date' => '2026-09-20', 'status' => 'released',
            'bom_snapshot' => ['id' => 1, 'output_quantity' => 1, 'lines' => [['component_product_id' => $component->id, 'quantity' => 2, 'scrap_percent' => 0, 'child' => null]], 'byproducts' => []],
        ]);

        Sanctum::actingAs($user, ['manufacturing:read', 'manufacturing:write']);
        $payload = ['production_order_id' => $order->id, 'date' => '2026-09-20', 'description' => 'Assembly material issue', 'lines' => [['product_id' => $component->id, 'quantity' => 2, 'unit_cost' => 4]]];
        $created = $this->postJson('/api/manufacturing/material-issues', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.production_order_id', $order->id)->assertJsonPath('data.document_type', 'issue');

        $this->postJson('/api/manufacturing/material-issues', array_merge($payload, ['lines' => [['product_id' => $unrelated->id, 'quantity' => 1]]]))
            ->assertStatus(422)->assertJsonPath('message', 'Product '.$unrelated->id.' is not an effective BOM component for this production order.');

        $this->getJson('/api/manufacturing/material-issue-variance?production_order_id='.$order->id)
            ->assertOk()->assertJsonPath('data.0.planned_quantity', 4)->assertJsonPath('data.0.material_issue_quantity', 0)->assertJsonPath('data.0.components.0.product_id', $component->id)->assertJsonPath('totals.production_order_count', 1);
    }

    public function test_approved_material_issue_can_create_only_one_reasoned_reversal_receipt(): void
    {
        $company = Company::create(['name' => 'Material Reversal Co', 'code' => 'MAT-REVERSAL']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Reversal Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Reversal Each', 'code' => 'EA-MAT-REV', 'status' => 1]);
        $category = Category::create(['name' => 'Reversal Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Reversal Finished', 'sku' => 'REV-FG', 'status' => 1, 'is_stock_item' => true]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Reversal Component', 'sku' => 'REV-COMP', 'status' => 1, 'is_stock_item' => true]);
        $bom = BillOfMaterial::create(['product_id' => $finished->id, 'code' => 'BOM-REV-1', 'name' => 'Reversal Test BOM', 'output_quantity' => 1, 'is_active' => true, 'effective_from' => '2026-01-01']);
        BomLine::create(['bom_id' => $bom->id, 'component_product_id' => $component->id, 'quantity' => 1, 'scrap_percent' => 0]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'order_no' => 'MO-REV-1', 'bom_id' => $bom->id, 'product_id' => $finished->id, 'planned_quantity' => 1, 'completed_quantity' => 0, 'planned_date' => '2026-09-20', 'status' => 'in_progress', 'bom_snapshot' => ['id' => $bom->id, 'output_quantity' => 1, 'lines' => [['component_product_id' => $component->id, 'quantity' => 1, 'scrap_percent' => 0, 'child' => null]], 'byproducts' => []]]);
        $source = InventoryDocument::create(['company_id' => $company->id, 'document_no' => 'ISS-REV-1', 'document_type' => 'issue', 'date' => '2026-09-20', 'description' => 'Approved material issue', 'status' => 'approved', 'production_order_id' => $order->id, 'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now()]);
        InventoryDocumentLine::create(['inventory_document_id' => $source->id, 'product_id' => $component->id, 'quantity' => 1, 'unit_cost' => 5]);

        Sanctum::actingAs($user, ['manufacturing:read', 'manufacturing:write']);
        $reversed = $this->postJson('/api/manufacturing/material-issues/'.$source->id.'/reverse', ['reason' => 'Corrected duplicate component issue']);
        $reversed->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.reversal_of_id', $source->id)->assertJsonPath('data.document_type', 'receipt');
        $this->getJson('/api/manufacturing/material-issues/reversals?updated_since=2026-09-01')
            ->assertOk()->assertJsonPath('data.0.id', $reversed->json('data.id'))->assertJsonPath('data.0.reversed_document.id', $source->id);
        $this->postJson('/api/manufacturing/material-issues/'.$source->id.'/reverse', ['reason' => 'Second correction'])
            ->assertStatus(422)->assertJsonPath('message', 'This material issue already has a reversal document.');
    }

    public function test_approved_standalone_issue_can_create_an_inventory_reversal(): void
    {
        $company = Company::create(['name' => 'Standalone Reversal Co', 'code' => 'STAND-REV']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Standalone Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Standalone Each', 'code' => 'EA-STAND-REV', 'status' => 1]);
        $category = Category::create(['name' => 'Standalone Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Standalone Issue Item', 'sku' => 'STAND-ITEM', 'status' => 1, 'is_stock_item' => true]);
        $source = InventoryDocument::create(['company_id' => $company->id, 'document_no' => 'ISS-STAND-1', 'document_type' => 'issue', 'date' => '2026-09-20', 'description' => 'Approved standalone issue', 'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now()]);
        InventoryDocumentLine::create(['inventory_document_id' => $source->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 3]);

        Sanctum::actingAs($user, ['inventory:write']);
        $created = $this->postJson('/api/inventory/documents/'.$source->id.'/reverse', ['reason' => 'Corrected standalone issue', 'external_reference' => 'REV-STAND-1']);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.reversal_of_id', $source->id)->assertJsonPath('data.document_type', 'receipt');
        $this->postJson('/api/inventory/documents/'.$source->id.'/reverse', ['reason' => 'Replay standalone issue', 'external_reference' => 'REV-STAND-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
    }
}
