<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Models\Unit;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerCreditExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_approval_blocks_cumulative_open_order_credit_exposure(): void
    {
        $company = Company::create(['name' => 'Credit Exposure Co', 'code' => 'CREDIT-EXPOSURE']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Exposure Customer',
            'is_active' => true,
            'credit_limit' => 200,
        ]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Credit Items', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Credit Supplier', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id,
            'name' => 'Backorder Item',
            'status' => 1,
            'lifecycle_status' => 'active',
            'is_stock_item' => true,
            'can_sell' => true,
            'quantity' => 0,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);

        $createOrder = function (int $number) use ($company, $maker, $customer, $product): SalesOrder {
            $order = SalesOrder::create([
                'company_id' => $company->id,
                'order_no' => 'CREDIT-ORDER-'.$number,
                'customer_id' => $customer->id,
                'date' => '2026-11-13',
                'status' => 'submitted',
                'allow_backorders' => true,
                'created_by' => $maker->id,
            ]);
            SalesOrderLine::create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
                'ordered_qty' => 6,
                'delivered_qty' => 0,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]);
            return $order;
        };

        Sanctum::actingAs($checker, ['sales:write']);
        $first = $createOrder(1);
        $this->postJson('/api/integration/sales-orders/'.$first->id.'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');

        $second = $createOrder(2);
        $this->postJson('/api/integration/sales-orders/'.$second->id.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Customer credit limit would be exceeded.');

        $this->assertDatabaseHas('sales_orders', ['id' => $first->id, 'status' => 'approved']);
        $this->assertDatabaseHas('sales_orders', ['id' => $second->id, 'status' => 'submitted']);
    }
}
