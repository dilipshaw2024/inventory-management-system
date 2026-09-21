<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_procurement_purchase_order_replay_returns_the_original_order(): void
    {
        $company = Company::create(['name' => 'PO Idempotency Co', 'code' => 'PO-IDEMPOTENCY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'PO Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'PO Each', 'code' => 'EA-PO-IDEM', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'PO Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'PO item', 'status' => 1, 'is_stock_item' => true,
            'can_purchase' => true,
        ]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = [
            'external_reference' => 'PO-EXTERNAL-1', 'supplier_id' => $supplier->id,
            'date' => '2026-09-20', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 7]],
        ];

        $created = $this->postJson('/api/integration/purchase-orders', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.status', 'submitted');
        $replayed = $this->postJson('/api/integration/purchase-orders', $payload + ['lines' => [['product_id' => $product->id, 'quantity' => 99, 'unit_price' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, PurchaseOrder::where('company_id', $company->id)->count());
    }
}
