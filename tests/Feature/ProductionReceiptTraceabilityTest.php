<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionReceiptTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_production_receipts_retain_distinct_batch_context_and_feed_output(): void
    {
        $company = Company::create(['name' => 'Receipt Trace Co', 'code' => 'RECEIPT-TRACE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['manufacturing:write', 'manufacturing:read']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Trace Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Trace Each', 'code' => 'TRACE-EA', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Trace Category', 'status' => 1]);
        $finished = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Trace finished', 'sku' => 'TRACE-FG', 'tracking_type' => 'batch', 'quantity' => 0, 'status' => 1, 'is_stock_item' => true]);
        $component = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Trace component', 'sku' => 'TRACE-COMP', 'quantity' => 10, 'status' => 1, 'is_stock_item' => true]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $component->id, 'movement_type' => 'receipt', 'quantity' => 10, 'unit_cost' => 0, 'posted_at' => now()]);
        $bom = BillOfMaterial::create(['company_id' => $company->id, 'product_id' => $finished->id, 'code' => 'BOM-TRACE', 'name' => 'Trace BOM', 'output_quantity' => 1, 'is_active' => true, 'approval_status' => 'approved']);
        $bom->lines()->create(['company_id' => $company->id, 'component_product_id' => $component->id, 'quantity' => 1]);
        $order = ProductionOrder::create(['company_id' => $company->id, 'bom_id' => $bom->id, 'product_id' => $finished->id, 'planned_quantity' => 2, 'planned_date' => '2026-09-29', 'status' => 'in_progress', 'order_no' => 'MO-TRACE-1']);
        StockReservation::create(['company_id' => $company->id, 'source_type' => $order->getMorphClass(), 'source_id' => $order->id, 'product_id' => $component->id, 'quantity' => 2, 'released_quantity' => 0, 'status' => 'active']);

        app(ProductionService::class)->complete($order->id, 1, ['external_reference' => 'TRACE-RECEIPT-1', 'output_batch_no' => 'TRACE-BATCH-1', 'output_manufacturing_date' => '2026-09-29', 'output_expiry_date' => '2027-09-29']);
        $replayed = app(ProductionService::class)->complete($order->id, 1, ['external_reference' => 'TRACE-RECEIPT-1', 'output_batch_no' => 'SHOULD-NOT-POST']);
        $this->assertSame(1.0, (float) $replayed->completed_quantity);
        $this->assertSame(1, ProductionReceipt::where('production_order_id', $order->id)->count());

        app(ProductionService::class)->complete($order->id, 1, ['external_reference' => 'TRACE-RECEIPT-2', 'output_batch_no' => 'TRACE-BATCH-2', 'output_manufacturing_date' => '2026-09-30']);

        $this->assertSame(2, ProductionReceipt::where('production_order_id', $order->id)->count());
        $this->assertDatabaseHas('production_receipts', ['production_order_id' => $order->id, 'external_reference' => 'TRACE-RECEIPT-1', 'quantity' => 1, 'net_cost' => 0]);
        $this->assertDatabaseHas('inventory_batches', ['product_id' => $finished->id, 'batch_no' => 'TRACE-BATCH-1']);
        $this->assertDatabaseHas('inventory_batches', ['product_id' => $finished->id, 'batch_no' => 'TRACE-BATCH-2']);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => ProductionReceipt::class, 'reference_id' => ProductionReceipt::where('external_reference', 'TRACE-RECEIPT-1')->value('id'), 'batch_id' => InventoryBatch::where('batch_no', 'TRACE-BATCH-1')->value('id')]);

        $feed = $this->getJson('/api/manufacturing/orders')->assertOk();
        $feed->assertJsonPath('data.0.receipts.0.external_reference', 'TRACE-RECEIPT-1')
            ->assertJsonPath('data.0.receipts.1.external_reference', 'TRACE-RECEIPT-2');
    }
}
