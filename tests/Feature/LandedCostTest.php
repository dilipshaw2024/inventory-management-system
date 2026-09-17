<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostConsumption;
use App\Models\LandedCost;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\LandedCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandedCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_landed_cost_cannot_be_allocated_again(): void
    {
        $company = Company::create(['name' => 'Landed Cost Co', 'code' => 'LC-TEST']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Freight Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Landed Cost Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Imported item', 'quantity' => 10, 'status' => 1,
        ]);
        $order = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-LC-TEST',
            'date' => now()->toDateString(), 'status' => 'approved',
        ]);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 10, 'received_qty' => 10, 'unit_price' => 10]);
        $receipt = GoodsReceipt::create([
            'company_id' => $company->id, 'purchase_order_id' => $order->id, 'grn_no' => 'GRN-LC-TEST',
            'date' => now()->toDateString(), 'status' => 'approved',
        ]);
        $receiptLine = $receipt->lines()->create([
            'purchase_order_line_id' => $orderLine->id, 'product_id' => $product->id,
            'received_qty' => 10, 'unit_cost' => 10,
        ]);
        $layer = InventoryCostLayer::create([
            'product_id' => $product->id, 'original_quantity' => 10, 'remaining_quantity' => 10,
            'unit_cost' => 10, 'source_type' => 'goods_receipt', 'source_id' => $receipt->id,
            'received_at' => now(),
        ]);
        InventoryCostConsumption::create([
            'cost_layer_id' => $layer->id, 'product_id' => $product->id,
            'quantity' => 4, 'unit_cost' => 10, 'total_cost' => 40, 'costing_method' => 'fifo',
        ]);
        $layer->update(['remaining_quantity' => 6]);
        $cost = LandedCost::create([
            'company_id' => $company->id, 'cost_no' => 'LC-TEST-001', 'goods_receipt_id' => $receipt->id,
            'cost_type' => 'freight', 'amount' => 20, 'allocation_method' => 'by_quantity', 'status' => 'pending',
        ]);

        app(LandedCostService::class)->approve($cost);

        app(LandedCostService::class)->reverse($cost->fresh(), 'Freight invoice was voided.');

        $this->assertDatabaseHas('landed_costs', ['id' => $cost->id, 'status' => 'reversed', 'reversal_reason' => 'Freight invoice was voided.']);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => '10.000000']);
        $this->assertDatabaseHas('inventory_cost_consumptions', ['cost_layer_id' => $layer->id, 'unit_cost' => '10.000000', 'total_cost' => '40.000000']);
        $this->assertSame(2, $cost->layerAdjustments()->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only pending landed costs can be approved.');
        app(LandedCostService::class)->approve($cost->fresh());

        $this->assertDatabaseHas('landed_costs', ['id' => $cost->id, 'status' => 'approved']);
        $this->assertDatabaseHas('landed_cost_allocations', ['landed_cost_id' => $cost->id, 'goods_receipt_line_id' => $receiptLine->id]);
        $this->assertSame(1, $cost->allocations()->count());
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => '12.000000']);
        $this->assertDatabaseHas('inventory_cost_consumptions', ['cost_layer_id' => $layer->id, 'unit_cost' => '12.000000', 'total_cost' => '48.000000']);
    }
}
