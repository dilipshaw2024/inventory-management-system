<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundleComponent;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\BundleFulfillmentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBundleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_components_can_be_replaced_and_read(): void
    {
        $company = Company::create(['name' => 'Bundle Co', 'code' => 'BUNDLE-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Bundle Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'BUNDLE-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Bundle Category', 'status' => 1]);
        $bundle = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Starter Bundle', 'sku' => 'BUNDLE-001', 'product_type' => 'bundle', 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Bundle Component', 'sku' => 'COMP-001', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write', 'inventory:read']);

        $this->putJson('/api/integration/products/'.$bundle->id.'/bundle-components', ['components' => [['product_id' => $component->id, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('data.product_id', $bundle->id)->assertJsonPath('data.components.0.quantity', 2);
        $this->getJson('/api/integration/products/'.$bundle->id.'/bundle-components')
            ->assertOk()->assertJsonPath('data.components.0.product_id', $component->id);
        $this->assertDatabaseHas('product_bundle_components', ['bundle_product_id' => $bundle->id, 'component_product_id' => $component->id, 'quantity' => 2]);
    }

    public function test_bundle_circular_reference_is_rejected(): void
    {
        $company = Company::create(['name' => 'Circular Bundle Co', 'code' => 'CIRCULAR-BUNDLE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Circular Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'CIRCULAR-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Circular Category', 'status' => 1]);
        $first = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'First Bundle', 'sku' => 'BUNDLE-A', 'product_type' => 'bundle', 'status' => 1]);
        $second = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Second Bundle', 'sku' => 'BUNDLE-B', 'product_type' => 'bundle', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write', 'inventory:read']);
        $this->putJson('/api/integration/products/'.$first->id.'/bundle-components', ['components' => [['product_id' => $second->id, 'quantity' => 1]]])->assertOk();
        $this->putJson('/api/integration/products/'.$second->id.'/bundle-components', ['components' => [['product_id' => $first->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonPath('message', 'Bundle components cannot create a circular bundle reference.');
    }

    public function test_bundle_return_line_expands_to_leaf_components(): void
    {
        $company = Company::create(['name' => 'Return Bundle Co', 'code' => 'RETURN-BUNDLE']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Return Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'RETURN-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Return Category', 'status' => 1]);
        $bundle = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Return Bundle', 'sku' => 'RETURN-BUNDLE-1', 'product_type' => 'bundle', 'status' => 1]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Return Component', 'sku' => 'RETURN-COMP-1', 'sales_price' => 10, 'status' => 1]);
        ProductBundleComponent::create(['company_id' => $company->id, 'bundle_product_id' => $bundle->id, 'component_product_id' => $component->id, 'quantity' => 2]);
        $expanded = app(BundleFulfillmentService::class)->expandReturnLine($bundle, 3, 4, 30, 5);
        $this->assertSame([['product_id' => $component->id, 'quantity' => 6.0, 'unit_cost' => 4.0, 'unit_price' => 5.0, 'tax_rate' => 5.0, 'serial_numbers' => null]], $expanded);
    }
}
