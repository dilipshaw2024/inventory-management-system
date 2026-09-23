<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_invoice_replay_returns_the_original_invoice(): void
    {
        [$company, $user, $supplier, $product, $order] = $this->purchaseFixture('INVOICE');
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 3, 'received_qty' => 3, 'unit_price' => 7]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = [
            'external_reference' => 'PINV-EXTERNAL-1', 'purchase_order_id' => $order->id,
            'invoice_date' => '2026-09-20', 'lines' => [[
                'purchase_order_line_id' => $orderLine->id, 'quantity' => 2, 'unit_price' => 7,
            ]],
        ];

        $created = $this->postJson('/api/integration/purchase-invoices', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/integration/purchase-invoices', $payload + ['lines' => [[
            'purchase_order_line_id' => $orderLine->id, 'quantity' => 99, 'unit_price' => 99,
        ]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, PurchaseInvoice::where('company_id', $company->id)->count());
    }

    public function test_goods_receipt_replay_returns_the_original_receipt(): void
    {
        [$company, $user, $supplier, $product, $order] = $this->purchaseFixture('RECEIPT');
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 3, 'received_qty' => 0, 'unit_price' => 7]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = [
            'external_reference' => 'GRN-EXTERNAL-1', 'purchase_order_id' => $order->id,
            'date' => '2026-09-20', 'lines' => [[
                'purchase_order_line_id' => $orderLine->id, 'quantity' => 2, 'unit_cost' => 7,
            ]],
        ];

        $created = $this->postJson('/api/integration/goods-receipts', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/integration/goods-receipts', $payload + ['lines' => [[
            'purchase_order_line_id' => $orderLine->id, 'quantity' => 99, 'unit_cost' => 99,
        ]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, \App\Models\GoodsReceipt::where('company_id', $company->id)->count());
    }

    public function test_goods_receipt_scanner_validation_rejects_mismatch_and_accepts_product_code(): void
    {
        [$company, $user, $supplier, $product, $order] = $this->purchaseFixture('RECEIVING-SCAN');
        $product->update(['sku' => 'RECEIVE-SCAN-1']);
        ProductBarcode::create(['product_id' => $product->id, 'code' => 'QR-RECEIVE-1', 'type' => 'qrcode']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Receiving Scan Branch', 'code' => 'RECEIVE-SCAN-BRANCH']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Receiving Scan Warehouse', 'code' => 'RECEIVE-SCAN-WH']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Receiving Scan Bin', 'code' => 'RECEIVE-SCAN-BIN', 'type' => 'bin', 'is_active' => true]);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 3, 'received_qty' => 0, 'unit_price' => 7]);
        Sanctum::actingAs($user, ['purchasing:write', 'integration:write']);
        $this->postJson('/api/integration/organization/locations/'.$location->id.'/barcodes', [
            'code' => 'QR-RECEIVE-BIN-1', 'type' => 'qrcode', 'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'QR-RECEIVE-BIN-1');
        $payload = [
            'external_reference' => 'GRN-SCAN-1', 'purchase_order_id' => $order->id, 'date' => '2026-09-20',
            'location_scan_code' => 'QR-RECEIVE-BIN-1',
            'lines' => [[
                'purchase_order_line_id' => $orderLine->id, 'quantity' => 2, 'unit_cost' => 7,
                'product_scan_code' => 'WRONG-RECEIVE-SCAN',
            ]],
        ];

        $this->postJson('/api/integration/goods-receipts', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The scanned product does not match '.$product->name.'.');
        $this->assertSame(0, \App\Models\GoodsReceipt::where('company_id', $company->id)->count());

        $payload['external_reference'] = 'GRN-SCAN-2';
        $payload['lines'][0]['product_scan_code'] = 'QR-RECEIVE-1';
        $created = $this->postJson('/api/integration/goods-receipts', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $this->assertDatabaseHas('audit_logs', ['action' => 'goods_receipt.created', 'auditable_id' => $created->json('data.id')]);
    }

    /** @return array{0: Company, 1: User, 2: Supplier, 3: Product, 4: PurchaseOrder} */
    private function purchaseFixture(string $suffix): array
    {
        $company = Company::create(['name' => 'Procurement '.$suffix.' Co', 'code' => 'PROC-'.$suffix]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Procurement '.$suffix.' Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each '.$suffix, 'code' => 'EA-PROC-'.$suffix, 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Procurement '.$suffix.' Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Procurement '.$suffix.' item', 'status' => 1,
            'is_stock_item' => true, 'can_purchase' => true,
        ]);
        $order = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-PROC-'.$suffix,
            'date' => '2026-09-20', 'status' => 'approved',
        ]);

        return [$company, $user, $supplier, $product, $order];
    }
}
