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

class PriceListIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_and_item_creation_replay_by_external_reference(): void
    {
        $company = Company::create(['name' => 'Price List Co', 'code' => 'PRICE-LIST-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Price List Supplier', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Price List Customer', 'status' => 1]);
        $unit = Unit::create(['name' => 'Price List Each', 'status' => 1]);
        $category = Category::create(['name' => 'Price List Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Price List Product', 'status' => 1]);

        Sanctum::actingAs($user, ['sales:write']);
        $created = $this->postJson('/api/integration/price-lists', [
            'name' => 'Retail Price List', 'list_type' => 'sales', 'currency_code' => 'USD', 'external_reference' => 'PRICE-LIST-REPLAY-1',
        ]);
        $created->assertCreated()->assertJsonPath('status', 'created');
        $listId = $created->json('data.id');

        $this->postJson('/api/integration/price-lists/customer-assignment', ['customer_id' => $customer->id, 'price_list_id' => $listId])
            ->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.sales_price_list_id', $listId);

        $this->postJson('/api/integration/price-lists', ['external_reference' => 'PRICE-LIST-REPLAY-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $listId);

        $item = $this->postJson('/api/integration/price-lists/'.$listId.'/items', [
            'external_reference' => 'PRICE-ITEM-REPLAY-1', 'product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 25,
        ]);
        $item->assertCreated()->assertJsonPath('status', 'created');
        $itemId = $item->json('data.id');

        $this->postJson('/api/integration/price-lists/'.$listId.'/items', ['external_reference' => 'PRICE-ITEM-REPLAY-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $itemId);

        $this->assertDatabaseCount('price_lists', 1);
        $this->assertDatabaseCount('price_list_items', 1);
    }

    public function test_supplier_can_be_assigned_to_a_purchase_price_list_through_integration_api(): void
    {
        $company = Company::create(['name' => 'Supplier Price List Co', 'code' => 'SUPPLIER-PRICE-LIST-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Assigned Supplier', 'is_active' => true]);
        $list = \App\Models\PriceList::create(['company_id' => $company->id, 'name' => 'Contract Purchase', 'list_type' => 'purchase', 'currency_code' => 'USD', 'is_active' => true]);

        Sanctum::actingAs($user, ['purchasing:write']);
        $this->postJson('/api/integration/price-lists/supplier-assignment', [
            'supplier_id' => $supplier->id,
            'price_list_id' => $list->id,
        ])->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.purchase_price_list_id', $list->id);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'purchase_price_list_id' => $list->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.purchase_price_list_updated', 'auditable_id' => $supplier->id]);

        $this->postJson('/api/integration/price-lists/supplier-assignment', [
            'supplier_id' => $supplier->id,
            'price_list_id' => null,
        ])->assertOk()->assertJsonPath('data.purchase_price_list_id', null);
    }

    public function test_price_list_and_quantity_breaks_can_be_updated_or_deactivated(): void
    {
        $company = Company::create(['name' => 'Price List Lifecycle Co', 'code' => 'PRICE-LIST-LIFECYCLE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $unit = Unit::create(['name' => 'Lifecycle Each', 'status' => 1]);
        $category = Category::create(['name' => 'Lifecycle Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Lifecycle Product', 'status' => 1]);

        Sanctum::actingAs($user, ['sales:write']);
        $list = $this->postJson('/api/integration/price-lists', ['name' => 'Lifecycle Retail', 'list_type' => 'sales', 'currency_code' => 'usd'])->assertCreated()->json('data');
        $item = $this->postJson('/api/integration/price-lists/'.$list['id'].'/items', ['product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 10])->assertCreated()->json('data');
        $this->patchJson('/api/integration/price-lists/'.$list['id'], ['name' => 'Lifecycle Retail Updated', 'currency_code' => 'eur'])->assertOk()->assertJsonPath('data.currency_code', 'EUR');
        $this->patchJson('/api/integration/price-list-items/'.$item['id'], ['minimum_quantity' => 5, 'unit_price' => 9.5, 'discount_percent' => 2])->assertOk()->assertJsonPath('data.minimum_quantity', '5.000000');
        $this->postJson('/api/integration/price-list-items/'.$item['id'].'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('price_list_items', ['id' => $item['id'], 'minimum_quantity' => 5, 'unit_price' => 9.5, 'is_active' => 0]);
    }
}
