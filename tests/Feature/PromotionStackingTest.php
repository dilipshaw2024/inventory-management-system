<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionStackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_stackable_promotions_are_applied_in_order_and_non_stackable_rules_are_rejected(): void
    {
        $company = Company::create(['name' => 'Promotion Stack Co', 'code' => 'PROMO-STACK']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Promotion Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Promotion Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Stackable item', 'status' => 1]);
        Promotion::create(['company_id' => $company->id, 'code' => 'STACK10', 'name' => 'Stack ten', 'type' => 'percentage', 'discount_value' => 10, 'stackable' => true, 'is_active' => true]);
        Promotion::create(['company_id' => $company->id, 'code' => 'STACK5', 'name' => 'Stack five', 'type' => 'percentage', 'discount_value' => 5, 'stackable' => true, 'is_active' => true]);
        Promotion::create(['company_id' => $company->id, 'code' => 'SINGLE5', 'name' => 'Single five', 'type' => 'percentage', 'discount_value' => 5, 'stackable' => false, 'is_active' => true]);
        Sanctum::actingAs($user, ['sales:write']);

        $result = app(PromotionService::class)->applyCodesToLines(['STACK10', 'STACK5'], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]], null, now()->toDateString());
        $this->assertCount(2, $result['promotions']);
        $this->assertEqualsWithDelta(15, $result['lines'][0]['discount'], 0.0001);

        $this->expectExceptionMessage('One or more promotion codes cannot be stacked.');
        app(PromotionService::class)->applyCodesToLines(['STACK10', 'SINGLE5'], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]], null, now()->toDateString());
    }

    public function test_sales_order_api_persists_all_stackable_promotion_ids_and_combined_discount(): void
    {
        $company = Company::create(['name' => 'Promotion API Co', 'code' => 'PROMO-API']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Promotion API Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Promotion API Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Promotion API Each', 'code' => 'PROMO-API-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Promotion API Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Promotion API Item', 'quantity' => 10, 'status' => 1,
        ]);
        $first = Promotion::create(['company_id' => $company->id, 'code' => 'API10', 'name' => 'API ten', 'type' => 'percentage', 'discount_value' => 10, 'stackable' => true, 'is_active' => true]);
        $second = Promotion::create(['company_id' => $company->id, 'code' => 'API5', 'name' => 'API five', 'type' => 'percentage', 'discount_value' => 5, 'stackable' => true, 'is_active' => true]);
        Sanctum::actingAs($user, ['sales:write']);

        $response = $this->postJson('/api/integration/sales-orders', [
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'promotion_codes' => ['API10', 'API5'],
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $response->assertCreated()->assertJsonPath('status', 'pending_approval');
        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'promotion_id' => $first->id]);
        $this->assertSame([$first->id, $second->id], SalesOrder::findOrFail($orderId)->promotion_ids);
        $this->assertDatabaseHas('sales_order_lines', ['sales_order_id' => $orderId, 'discount_amount' => 15]);
    }
}
