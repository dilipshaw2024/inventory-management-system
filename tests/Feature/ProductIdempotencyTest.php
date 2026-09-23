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

class ProductIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_replay_returns_the_original_product(): void
    {
        $company = Company::create(['name' => 'Product Replay Co', 'code' => 'PRODUCT-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Product Replay Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Product Replay Each', 'code' => 'EA-PRODUCT-REPLAY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Product Replay Category', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write']);
        $payload = ['external_reference' => 'PRODUCT-REPLAY-1', 'name' => 'Replay Product', 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'tracking_type' => 'none', 'product_type' => 'stock', 'status' => true, 'auto_sku' => true];

        $created = $this->postJson('/api/inventory/products', $payload);
        $created->assertCreated()->assertJsonPath('status', 'created');
        $replayed = $this->postJson('/api/inventory/products', $payload + ['name' => 'Changed Replay Name']);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, Product::where('company_id', $company->id)->count());
    }

    public function test_company_costing_defaults_apply_when_product_payload_omits_costing_fields(): void
    {
        $company = Company::create(['name' => 'Product Costing Defaults Co', 'code' => 'PRODUCT-COSTING-DEFAULTS']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Costing Defaults Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Costing Defaults Each', 'code' => 'EA-COSTING-DEFAULTS', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Costing Defaults Category', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write', 'accounting:write']);

        $this->patchJson('/api/accounting/settings', [
            'default_inventory_costing_method' => 'standard',
            'default_standard_cost' => 23.75,
        ])->assertOk()->assertJsonPath('data.default_inventory_costing_method', 'standard');

        $response = $this->postJson('/api/inventory/products', [
            'external_reference' => 'PRODUCT-COSTING-DEFAULTS-1', 'name' => 'Default Cost Product',
            'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'tracking_type' => 'none', 'product_type' => 'stock', 'status' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.costing_method', 'standard');
        $this->assertEquals(23.75, (float) $response->json('data.standard_cost'));
        $this->assertDatabaseHas('products', ['id' => $response->json('data.id'), 'costing_method' => 'standard', 'standard_cost' => 23.75]);
    }
}
