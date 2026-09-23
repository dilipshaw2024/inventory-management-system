<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductCostingPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCostingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_costing_policy_is_inactive_until_effective_and_is_tenant_visible(): void
    {
        $company = Company::create(['name' => 'Costing Policy Co', 'code' => 'COSTING-POLICY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Costing Policy Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Costing Policy Each', 'code' => 'EA-COSTING-POLICY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Costing Policy Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Policy Product', 'sku' => 'COSTING-POLICY-1', 'status' => 1, 'costing_method' => 'fifo',
        ]);
        $future = CarbonImmutable::now()->addDay();

        app(ProductCostingPolicyService::class)->schedule($product, 'standard', 31.5, $future, $user->id, 'Quarterly standard-cost refresh.');

        $service = app(ProductCostingPolicyService::class);
        $this->assertSame('fifo', $service->resolve($product)['costing_method']);
        $this->assertSame('standard', $service->resolve($product, $future->addSecond())['costing_method']);
        $this->assertEquals(31.5, $service->resolve($product, $future->addSecond())['standard_cost']);

        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/products/'.$product->id.'/costing-policies')
            ->assertOk()->assertJsonPath('product_id', $product->id)->assertJsonPath('data.0.costing_method', 'standard');
    }
}
