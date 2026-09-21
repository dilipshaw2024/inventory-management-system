<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_replay_returns_the_original_order(): void
    {
        $company = Company::create(['name' => 'SO Idempotency Co', 'code' => 'SO-IDEMPOTENCY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Idempotent Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'SO Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'SO Each', 'code' => 'EA-SO-IDEM', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'SO Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'SO item', 'status' => 1, 'is_stock_item' => true,
            'can_sell' => true, 'sales_price' => 10, 'quantity' => 5,
        ]);
        Sanctum::actingAs($user, ['sales:write']);
        $payload = [
            'external_reference' => 'SO-EXTERNAL-1', 'customer_id' => $customer->id,
            'date' => '2026-09-20', 'paid_status' => 'full_due',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]],
        ];

        $created = $this->postJson('/api/integration/sales-orders', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.status', 'submitted');
        $replayed = $this->postJson('/api/integration/sales-orders', $payload);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, SalesOrder::where('company_id', $company->id)->count());
    }
}
