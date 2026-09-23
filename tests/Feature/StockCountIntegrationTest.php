<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockCountIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_can_create_approve_and_post_stock_count_variance(): void
    {
        $company = Company::create(['name' => 'Count Integration Co', 'code' => 'COUNT-API']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Count Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-COUNT', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Count Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Count item', 'sku' => 'COUNT-SCAN-1', 'quantity' => 10, 'purchase_price' => 4, 'status' => 1]);

        Sanctum::actingAs($creator, ['inventory:write', 'inventory:read']);
        $created = $this->postJson('/api/inventory/counts', [
            'count_no' => 'CNT-API-1', 'external_reference' => 'wms-count-1001', 'count_date' => '2026-09-17', 'description' => 'Cycle count',
            'lines' => [['product_id' => $product->id, 'product_scan_code' => 'COUNT-SCAN-1', 'counted_quantity' => 7]],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.lines.0.system_quantity', '10.000000');
        $countId = $created->json('data.id');
        $this->getJson('/api/inventory/counts/'.$countId.'/reconciliation')->assertOk()
            ->assertJsonPath('summary.line_count', 1)->assertJsonPath('summary.stale_line_count', 0)
            ->assertJsonPath('data.0.current_system_quantity', 10)->assertJsonPath('data.0.current_variance_quantity', -3)
            ->assertJsonPath('read_only', true);
        $this->getJson('/api/inventory/counts/reconciliation?status=submitted')
            ->assertOk()->assertJsonPath('summary.count_count', 1)
            ->assertJsonPath('summary.variance_line_count', 1)
            ->assertJsonPath('summary.signed_variance_quantity', -3)
            ->assertJsonPath('data.0.absolute_variance_quantity', 3)
            ->assertJsonPath('meta.read_only', true);
        $this->postJson('/api/inventory/counts', [
            'count_no' => 'CNT-API-REPLAY', 'external_reference' => 'wms-count-1001', 'count_date' => '2026-09-17',
            'lines' => [['product_id' => $product->id, 'counted_quantity' => 99]],
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $countId);

        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 7]);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $countId, 'movement_type' => 'adjustment_out', 'quantity' => 3]);
    }

    public function test_integration_recount_requires_a_second_count_before_approval(): void
    {
        $company = Company::create(['name' => 'Recount Integration Co', 'code' => 'RECOUNT-API']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Recount Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-RECOUNT', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Recount Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Recount item', 'quantity' => 5, 'purchase_price' => 2, 'status' => 1]);

        Sanctum::actingAs($creator, ['inventory:write', 'inventory:read']);
        $countId = $this->postJson('/api/inventory/counts', ['count_no' => 'CNT-API-2', 'count_date' => '2026-09-17', 'lines' => [['product_id' => $product->id, 'counted_quantity' => 4]]])->json('data.id');
        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/recount-request', ['recount_reason' => 'Variance requires verification'])->assertOk()->assertJsonPath('status', 'recount_required');
        $this->postJson('/api/inventory/counts/'.$countId.'/recount', ['lines' => [['product_id' => $product->id, 'counted_quantity' => 5]]])->assertOk()->assertJsonPath('status', 'pending_approval');
        $this->postJson('/api/inventory/counts/'.$countId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('inventory_movements', ['reference_id' => $countId]);
    }
}
