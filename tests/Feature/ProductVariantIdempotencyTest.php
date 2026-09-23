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

class ProductVariantIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_variant_creation_replay_returns_the_original_variant(): void
    {
        $company = Company::create(['name' => 'Variant Replay Co', 'code' => 'VARIANT-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Variant Replay Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Variant Replay Each', 'code' => 'EA-VARIANT-REPLAY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Variant Replay Category', 'status' => 1]);
        $parent = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Variant Parent', 'sku' => 'VARIANT-PARENT-1', 'status' => 1, 'lifecycle_status' => 'active']);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
        $payload = ['external_reference' => 'VARIANT-REPLAY-1', 'name' => 'Blue Variant', 'auto_sku' => true, 'purchase_price' => 12.50, 'sales_price' => 19.99, 'tax_rate' => 5];

        $created = $this->postJson('/api/inventory/products/'.$parent->id.'/variants', $payload);
        $created->assertCreated()->assertJsonPath('status', 'created');
        $this->assertEquals(12.5, (float) $created->json('data.purchase_price'));
        $this->assertEquals(19.99, (float) $created->json('data.sales_price'));
        $this->assertEquals(5, (float) $created->json('data.tax_rate'));
        $this->patchJson('/api/inventory/products/'.$created->json('data.id').'/variant', ['sales_price' => 21.25, 'lifecycle_status' => 'discontinued', 'can_sell' => false])
            ->assertOk()->assertJsonPath('status', 'updated');
        $this->assertEquals(21.25, (float) Product::find($created->json('data.id'))->sales_price);
        $this->assertSame('discontinued', Product::find($created->json('data.id'))->lifecycle_status);
        $this->getJson('/api/inventory/products?is_variant=1&parent_product_id='.$parent->id)
            ->assertOk()->assertJsonPath('data.0.id', $created->json('data.id'))->assertJsonPath('data.0.is_variant', 1)
            ->assertJsonPath('data.0.parent_product.id', $parent->id);
        $replayed = $this->postJson('/api/inventory/products/'.$parent->id.'/variants', $payload + ['name' => 'Changed Variant Name']);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, Product::where('company_id', $company->id)->where('parent_product_id', $parent->id)->count());
    }

    public function test_variant_inherits_parent_inventory_costing_policy(): void
    {
        $company = Company::create(['name' => 'Variant Costing Co', 'code' => 'VARIANT-COSTING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Variant Costing Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Variant Costing Each', 'code' => 'EA-VARIANT-COSTING', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Variant Costing Category', 'status' => 1]);
        $parent = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Moving Average Parent', 'sku' => 'VARIANT-COSTING-PARENT', 'status' => 1, 'lifecycle_status' => 'active',
            'costing_method' => 'moving_average', 'standard_cost' => 17.25,
        ]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $response = $this->postJson('/api/inventory/products/'.$parent->id.'/variants', ['external_reference' => 'VARIANT-COSTING-1', 'name' => 'Costing Variant']);

        $response->assertCreated()->assertJsonPath('data.costing_method', 'moving_average');
        $this->assertEquals(17.25, (float) $response->json('data.standard_cost'));
        $this->assertDatabaseHas('products', ['id' => $response->json('data.id'), 'costing_method' => 'moving_average', 'standard_cost' => 17.25]);
    }
}
