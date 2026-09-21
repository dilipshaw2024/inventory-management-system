<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAdjustmentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_adjustment_replay_returns_the_original_adjustment(): void
    {
        $company = Company::create(['name' => 'Adjustment Replay Co', 'code' => 'ADJUSTMENT-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Adjustment Replay Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Adjustment Replay Each', 'code' => 'EA-ADJUSTMENT-REPLAY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Adjustment Replay Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Adjustment Replay Item', 'status' => 1, 'is_stock_item' => true]);
        Sanctum::actingAs($user, ['inventory:write']);
        $payload = ['external_reference' => 'ADJUSTMENT-REPLAY-1', 'date' => '2026-09-20', 'reason_code' => 'cycle_count', 'description' => 'Cycle count correction', 'lines' => [['product_id' => $product->id, 'direction' => 'in', 'quantity' => 4, 'unit_cost' => 8]]];

        $created = $this->postJson('/api/inventory/adjustments', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/inventory/adjustments', $payload + ['lines' => [['product_id' => $product->id, 'direction' => 'out', 'quantity' => 99, 'unit_cost' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, InventoryAdjustment::where('company_id', $company->id)->count());
    }
}
