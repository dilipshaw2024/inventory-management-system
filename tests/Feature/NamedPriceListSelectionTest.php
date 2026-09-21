<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NamedPriceListSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_purchase_orders_persist_and_apply_explicit_named_price_lists(): void
    {
        $company = Company::create(['name' => 'Named Pricing Co', 'code' => 'NAMED-PRICING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Named Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Named Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Named Each', 'code' => 'EA-NAMED', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Named Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Named item', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true, 'can_purchase' => true, 'quantity' => 10]);
        $salesList = PriceList::create(['company_id' => $company->id, 'name' => 'Wholesale sales', 'list_type' => 'sales', 'currency_code' => 'USD', 'is_active' => true]);
        $purchaseList = PriceList::create(['company_id' => $company->id, 'name' => 'Contract purchase', 'list_type' => 'purchase', 'currency_code' => 'USD', 'is_active' => true]);
        PriceListItem::create(['company_id' => $company->id, 'price_list_id' => $salesList->id, 'product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 21, 'discount_percent' => 0, 'is_active' => true]);
        PriceListItem::create(['company_id' => $company->id, 'price_list_id' => $purchaseList->id, 'product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 7, 'discount_percent' => 0, 'is_active' => true]);
        Sanctum::actingAs($user, ['sales:write', 'purchasing:write']);

        $sales = $this->postJson('/api/integration/sales-orders', [
            'customer_id' => $customer->id, 'price_list_id' => $salesList->id, 'date' => '2026-09-20',
            'lines' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $sales->assertCreated()->assertJsonPath('data.price_list_id', $salesList->id)->assertJsonPath('data.lines.0.unit_price', '21.000000');
        $this->assertDatabaseHas('sales_orders', ['id' => $sales->json('data.id'), 'price_list_id' => $salesList->id]);

        $purchase = $this->postJson('/api/integration/purchase-orders', [
            'supplier_id' => $supplier->id, 'price_list_id' => $purchaseList->id, 'date' => '2026-09-20',
            'lines' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $purchase->assertCreated()->assertJsonPath('data.price_list_id', $purchaseList->id)->assertJsonPath('data.lines.0.unit_price', '7.000000');
        $this->assertDatabaseHas('purchase_orders', ['id' => $purchase->json('data.id'), 'price_list_id' => $purchaseList->id]);
        $this->assertSame(1, SalesOrder::where('price_list_id', $salesList->id)->count());
        $this->assertSame(1, PurchaseOrder::where('price_list_id', $purchaseList->id)->count());
    }
}
