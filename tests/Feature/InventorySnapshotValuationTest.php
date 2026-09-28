<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventorySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventorySnapshotValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_uses_historical_cost_layer_value_when_available(): void
    {
        $company = Company::create(['name' => 'Snapshot Valuation Co', 'code' => 'SNAP-VAL']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Snapshot Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Snapshot Each', 'code' => 'SNAP-EA', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Snapshot Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Snapshot item', 'sku' => 'SNAP-ITEM', 'status' => 1]);
        $receipt = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 10, 'posted_at' => '2026-01-10 10:00:00']);
        $issue = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 12, 'posted_at' => '2026-01-20 10:00:00']);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 5, 'remaining_quantity' => 3, 'unit_cost' => 12, 'received_at' => '2026-01-10 10:00:00']);
        InventoryCostConsumption::create(['cost_layer_id' => $layer->id, 'product_id' => $product->id, 'movement_id' => $issue->id, 'quantity' => 2, 'unit_cost' => 12, 'total_cost' => 24, 'costing_method' => 'fifo']);

        $snapshot = app(InventorySnapshotService::class)->capture($company->id, '2026-01-31');
        $row = collect($snapshot->rows)->firstWhere('product_id', $product->id);

        $this->assertSame(26.0, (float) $row['balance_value']);
        $this->assertSame(36.0, (float) $row['cost_layer_value']);
        $this->assertSame(36.0, (float) $row['valuation_value']);
        $this->assertSame('cost_layers', $row['valuation_source']);
        $this->assertNotNull($receipt->id);

        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]), ['inventory:read']);
        $this->getJson('/api/inventory/reconciliation-snapshots/'.$snapshot->id.'?product_id='.$product->id)
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('summary.row_count', 1)
            ->assertJsonPath('summary.valuation_value', 36)->assertJsonPath('data.rows.0.valuation_source', 'cost_layers');
    }
}
