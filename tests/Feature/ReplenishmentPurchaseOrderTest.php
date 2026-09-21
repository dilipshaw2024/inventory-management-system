<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplenishmentPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_replenishment_endpoint_creates_and_replays_one_draft_order(): void
    {
        $company = Company::create(['name' => 'Purchase Replenishment Co', 'code' => 'PURCHASE-REPLENISH']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Replenishment Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Replenishment Each', 'status' => 1]);
        $category = Category::create(['name' => 'Replenishment Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Replenishment item', 'status' => 1,
            'is_stock_item' => true, 'reorder_level' => 10, 'purchase_price' => 5,
        ]);
        InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt',
            'quantity' => 2, 'unit_cost' => 5, 'posted_at' => now(),
        ]);

        Sanctum::actingAs($user, ['inventory:write']);
        $payload = ['product_id' => $product->id, 'quantity' => 6, 'external_reference' => 'REPLENISHMENT-PO-1'];
        $created = $this->postJson('/api/inventory/replenishment/purchase-orders', $payload);

        $created->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->assertJsonPath('approval_required', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.ordered_qty', '6.000000');
        $this->assertSame(1, PurchaseOrder::where('company_id', $company->id)->count());
        $this->assertSame(1, PurchaseOrderLine::where('product_id', $product->id)->count());

        $replayed = $this->postJson('/api/inventory/replenishment/purchase-orders', $payload);
        $replayed->assertOk()
            ->assertJsonPath('status', 'duplicate_ignored')
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, PurchaseOrder::where('company_id', $company->id)->count());
    }
}
