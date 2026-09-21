<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
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

    public function test_replenishment_purchase_order_preserves_target_location(): void
    {
        $company = Company::create(['name' => 'Located Replenishment Co', 'code' => 'LOCATED-REPLENISH']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'LOC-MAIN']);
        $warehouse = $branch->warehouses()->create(['name' => 'Central', 'code' => 'LOC-CENTRAL']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Target Bin', 'code' => 'TARGET-BIN', 'type' => 'bin', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Located Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Located Each', 'status' => 1]);
        $category = Category::create(['name' => 'Located Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Located item', 'status' => 1, 'is_stock_item' => true, 'purchase_price' => 5]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'reorder_point' => 10, 'min_stock' => 10, 'lead_time_days' => 0, 'is_active' => true]);
        $availability = Mockery::mock(\App\Services\InventoryAvailabilityService::class);
        $availability->shouldReceive('available')->andReturn(2.0);
        $this->app->instance(\App\Services\InventoryAvailabilityService::class, $availability);
        $prices = Mockery::mock(\App\Services\SupplierProductPriceService::class);
        $prices->shouldReceive('bestFor')->andReturnNull();
        $this->app->instance(\App\Services\SupplierProductPriceService::class, $prices);

        Sanctum::actingAs($user, ['inventory:write', 'inventory:read', 'integration:read']);
        $created = $this->postJson('/api/inventory/replenishment/purchase-orders', ['product_id' => $product->id, 'location_id' => $location->id, 'external_reference' => 'LOCATED-REPLENISHMENT-PO'])->assertCreated();
        $orderId = (int) $created->json('data.id');
        $this->assertDatabaseHas('purchase_order_lines', ['purchase_order_id' => $orderId, 'location_id' => $location->id]);
        $this->getJson('/api/integration/purchase-orders')->assertOk()->assertJsonPath('data.0.lines.0.location_id', $location->id);
    }
}
