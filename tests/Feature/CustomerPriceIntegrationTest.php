<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPriceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_price_api_supports_customer_group_and_channel_scopes(): void
    {
        $company = Company::create(['name' => 'Customer Pricing Co', 'code' => 'CUSTOMER-PRICING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Retail Customer', 'status' => 1, 'customer_group' => 'retail', 'sales_channel' => 'web']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Customer Price Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Customer Price Each', 'status' => 1]);
        $category = Category::create(['name' => 'Customer Price Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Customer Price Product', 'status' => 1]);

        Sanctum::actingAs($user, ['sales:read', 'sales:write']);
        $created = $this->postJson('/api/integration/customer-prices', [
            'customer_group' => 'retail', 'sales_channel' => 'web', 'product_id' => $product->id, 'minimum_quantity' => 5,
            'unit_price' => 18, 'currency_code' => 'usd', 'discount_percent' => 2, 'external_reference' => 'CUSTOMER-PRICE-1',
        ]);
        $created->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('data.currency_code', 'USD');
        $priceId = $created->json('data.id');

        $this->postJson('/api/integration/customer-prices', ['external_reference' => 'CUSTOMER-PRICE-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $priceId);
        $this->patchJson('/api/integration/customer-prices/'.$priceId, ['unit_price' => 17, 'discount_percent' => 3])
            ->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.unit_price', '17.000000')->assertJsonPath('data.discount_percent', '3.0000');
        $this->getJson('/api/integration/customer-prices?customer_group=retail&sales_channel=web&product_id='.$product->id.'&quantity=5&currency_code=USD')
            ->assertOk()->assertJsonPath('data.0.id', $priceId)->assertJsonPath('data.0.unit_price', '17.000000');
        $this->postJson('/api/integration/customer-prices/'.$priceId.'/deactivate')
            ->assertOk()->assertJsonPath('status', 'deactivated')->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('customer_product_prices', ['id' => $priceId, 'company_id' => $company->id, 'is_active' => 0]);

        $customerPrice = $this->postJson('/api/integration/customer-prices', [
            'customer_id' => $customer->id, 'product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 20,
            'currency_code' => 'USD', 'external_reference' => 'CUSTOMER-PRICE-2',
        ]);
        $customerPrice->assertCreated()->assertJsonPath('data.customer.id', $customer->id);
    }
}
