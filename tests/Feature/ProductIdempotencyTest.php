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
}
