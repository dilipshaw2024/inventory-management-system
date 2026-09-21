<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Product;
use App\Models\ProductClassification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductClassificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_master_is_tenant_safe_and_idempotent(): void
    {
        $company = Company::create(['name' => 'Classification Co', 'code' => 'CLASSIFICATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write', 'accounting:read']);
        $payload = ['scheme' => 'HSN', 'code' => '847130', 'jurisdiction' => 'IN', 'description' => 'Portable computers', 'external_reference' => 'HSN-IN-847130'];

        $created = $this->postJson('/api/inventory/product-classifications', $payload);
        $created->assertCreated()->assertJsonPath('data.scheme', 'hsn')->assertJsonPath('data.code', '847130')->assertJsonPath('data.jurisdiction', 'IN');
        $id = $created->json('data.id');
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Classification Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Classification Each', 'code' => 'CLASS-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Classification Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'classification_id' => $id, 'name' => 'Classified product', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'CLASS-TAX-1', 'date' => '2026-09-18', 'status' => 1, 'subtotal_amount' => 100, 'tax_amount' => 18, 'total_amount' => 118]);
        $invoice->invoice_details()->create(['date' => '2026-09-18', 'product_id' => $product->id, 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'tax_rate' => 18, 'tax_amount' => 18, 'status' => 1]);
        $this->getJson('/api/accounting/tax-report?from=2026-09-01&to=2026-09-30&classification_id='.$id)->assertOk()->assertJsonPath('data.0.classification.code', '847130')->assertJsonPath('meta.classification_id', $id);
        $this->postJson('/api/inventory/product-classifications', $payload)->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $id);
        $this->getJson('/api/inventory/product-classifications?scheme=HSN&jurisdiction=in')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/inventory/product-classifications/'.$id, ['description' => 'Portable computers, updated'])->assertOk()->assertJsonPath('data.description', 'Portable computers, updated');
        $this->postJson('/api/inventory/product-classifications/'.$id.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('product_classifications', ['id' => $id, 'company_id' => $company->id, 'scheme' => 'hsn', 'code' => '847130', 'is_active' => 0]);
        $this->assertSame(1, ProductClassification::where('company_id', $company->id)->count());
    }
}
