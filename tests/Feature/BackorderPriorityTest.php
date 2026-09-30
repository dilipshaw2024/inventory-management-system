<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Services\BackorderAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackorderPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backorder_allocator_honors_priority_before_requested_date(): void
    {
        $company = Company::create(['name' => 'Priority Co', 'code' => 'BACKORDER-PRIORITY']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Priority Customer', 'status' => 1]);
        $unit = Unit::create(['name' => 'Priority Each', 'status' => 1]);
        $category = Category::create(['name' => 'Priority Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Priority item', 'sku' => 'BACKORDER-PRIORITY-001',
            'quantity' => 0, 'status' => 1, 'is_stock_item' => true,
        ]);
        InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'movement_type' => 'receipt', 'quantity' => 3, 'unit_cost' => 10, 'posted_at' => now(),
        ]);

        $low = SalesOrder::create([
            'company_id' => $company->id, 'order_no' => 'SO-LOW-PRIORITY', 'customer_id' => $customer->id,
            'date' => '2026-09-29', 'requested_date' => '2026-09-30', 'status' => 'approved',
            'allow_backorders' => true, 'fulfillment_priority' => 10,
        ]);
        $high = SalesOrder::create([
            'company_id' => $company->id, 'order_no' => 'SO-HIGH-PRIORITY', 'customer_id' => $customer->id,
            'date' => '2026-09-29', 'requested_date' => '2026-10-05', 'status' => 'approved',
            'allow_backorders' => true, 'fulfillment_priority' => 90,
        ]);
        foreach ([$low, $high] as $order) {
            SalesOrderLine::create([
                'sales_order_id' => $order->id, 'product_id' => $product->id,
                'ordered_qty' => 2, 'delivered_qty' => 0, 'unit_price' => 10,
            ]);
        }

        $allocated = app(BackorderAllocationService::class)->allocateForCompany($company->id);

        $this->assertEqualsWithDelta(3.0, $allocated, 0.000001);
        $this->assertDatabaseHas('stock_reservations', [
            'sales_order_line_id' => $high->lines()->firstOrFail()->id, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'sales_order_line_id' => $low->lines()->firstOrFail()->id, 'quantity' => 1,
        ]);
        $this->assertSame(1, StockReservation::where('sales_order_line_id', $high->lines()->firstOrFail()->id)->count());
    }

    public function test_sales_order_api_persists_and_exposes_fulfillment_priority(): void
    {
        $company = Company::create(['name' => 'Priority API Co', 'code' => 'BACKORDER-PRIORITY-API']);
        $user = \App\Models\User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'API Customer', 'status' => 1]);
        $unit = Unit::create(['name' => 'API Priority Each', 'status' => 1]);
        $category = Category::create(['name' => 'API Priority Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'API priority item', 'sku' => 'BACKORDER-PRIORITY-API-001',
            'quantity' => 10, 'status' => 1, 'is_stock_item' => true,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user, ['sales:write', 'sales:read']);
        $response = $this->postJson('/api/integration/sales-orders', [
            'external_reference' => 'BACKORDER-PRIORITY-API-1', 'customer_id' => $customer->id,
            'date' => '2026-09-29', 'allow_backorders' => true, 'fulfillment_priority' => 87,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $response->assertCreated()->assertJsonPath('data.fulfillment_priority', 87);
        $this->assertDatabaseHas('sales_orders', ['external_reference' => 'BACKORDER-PRIORITY-API-1', 'fulfillment_priority' => 87]);
    }
}
