<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ErpSettingService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThreeWayMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_price_variance_uses_linked_approved_receipt_cost(): void
    {
        $company = Company::create(['name' => 'Matching Co', 'code' => 'MATCH-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Matching Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Matching Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Receipt-priced item', 'quantity' => 0, 'status' => 1,
        ]);
        $order = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-MATCH-TEST',
            'date' => now()->toDateString(), 'status' => 'approved',
        ]);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 10, 'received_qty' => 10, 'unit_price' => 10]);
        $receipt = GoodsReceipt::create([
            'company_id' => $company->id, 'purchase_order_id' => $order->id, 'grn_no' => 'GRN-MATCH-TEST',
            'date' => now()->toDateString(), 'status' => 'approved',
        ]);
        $receiptLine = $receipt->lines()->create([
            'purchase_order_line_id' => $orderLine->id, 'product_id' => $product->id,
            'received_qty' => 10, 'unit_cost' => 12,
        ]);
        $invoice = PurchaseInvoice::create([
            'company_id' => $company->id, 'invoice_no' => 'PINV-MATCH-TEST', 'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id, 'invoice_date' => now()->toDateString(), 'status' => 'pending',
            'total_amount' => 120, 'subtotal_amount' => 120,
        ]);
        $invoice->lines()->create([
            'purchase_order_line_id' => $orderLine->id, 'goods_receipt_line_id' => $receiptLine->id,
            'product_id' => $product->id, 'quantity' => 10, 'unit_price' => 12, 'line_total' => 120,
        ]);
        app(ErpSettingService::class)->put('purchase_price_variance_percent', 5, 'float', $company->id);

        app(PurchaseInvoiceService::class)->approve($invoice->fresh('lines'));

        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->id, 'status' => 'approved']);
        $token = $user->createToken('variance-feed', ['purchasing:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/integration/purchase-price-variances?supplier_id='.$supplier->id)
            ->assertOk()->assertJsonPath('summary.line_count', 1)->assertJsonPath('data.0.baseline_source', 'approved_receipt')
            ->assertJsonPath('data.0.variance_percent', 0)->assertJsonPath('data.0.reconciliation_status', 'within_tolerance');
    }

    public function test_invoice_rejects_receipt_from_another_purchase_order(): void
    {
        $company = Company::create(['name' => 'Matching Co 2', 'code' => 'MATCH-TEST-2']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Matching Supplier 2', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each 2', 'status' => 1]);
        $category = Category::create(['name' => 'Matching Category 2', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Mismatched item', 'quantity' => 0, 'status' => 1]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-MATCH-TEST-2', 'date' => now()->toDateString(), 'status' => 'approved']);
        $otherOrder = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-MATCH-TEST-3', 'date' => now()->toDateString(), 'status' => 'approved']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 1, 'received_qty' => 1, 'unit_price' => 10]);
        $otherLine = $otherOrder->lines()->create(['product_id' => $product->id, 'ordered_qty' => 1, 'received_qty' => 1, 'unit_price' => 10]);
        $receipt = GoodsReceipt::create(['company_id' => $company->id, 'purchase_order_id' => $otherOrder->id, 'grn_no' => 'GRN-MATCH-TEST-2', 'date' => now()->toDateString(), 'status' => 'approved']);
        $receiptLine = $receipt->lines()->create(['purchase_order_line_id' => $otherLine->id, 'product_id' => $product->id, 'received_qty' => 1, 'unit_cost' => 10]);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'invoice_no' => 'PINV-MATCH-TEST-2', 'supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'invoice_date' => now()->toDateString(), 'status' => 'pending', 'total_amount' => 10, 'subtotal_amount' => 10]);
        $invoice->lines()->create(['purchase_order_line_id' => $orderLine->id, 'goods_receipt_line_id' => $receiptLine->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match an approved receipt');
        app(PurchaseInvoiceService::class)->approve($invoice->fresh('lines'));
    }

    public function test_final_receipt_shortage_can_be_resolved_by_checker(): void
    {
        $company = Company::create(['name' => 'Receipt Discrepancy Co', 'code' => 'RECEIPT-DISCREPANCY']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Discrepancy Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Discrepancy Each', 'status' => 1]);
        $category = Category::create(['name' => 'Discrepancy Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Short-delivered item', 'quantity' => 0, 'status' => 1]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-DISCREPANCY', 'date' => now()->toDateString(), 'status' => 'approved']);
        $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 10, 'received_qty' => 0, 'unit_price' => 5]);
        Sanctum::actingAs($creator, ['purchasing:write']);
        $created = $this->postJson('/api/integration/goods-receipts', ['purchase_order_id' => $order->id, 'date' => now()->toDateString(), 'is_final_delivery' => true, 'discrepancy_reason' => 'Supplier confirmed balance will not ship.', 'lines' => [['purchase_order_line_id' => $order->lines()->first()->id, 'quantity' => 6, 'unit_cost' => 5]]]);
        $created->assertCreated();
        $receiptId = (int) $created->json('data.id');

        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $approved = $this->postJson('/api/integration/goods-receipts/'.$receiptId.'/approve');
        $approved->assertOk()->assertJsonPath('data.discrepancy_status', 'open');

        $feed = $this->getJson('/api/integration/goods-receipts?discrepancy_status=open&is_final_delivery=1');
        $feed->assertOk()->assertJsonPath('data.0.id', $receiptId)->assertJsonPath('data.0.is_final_delivery', true)->assertJsonPath('data.0.discrepancy_reason', 'Supplier confirmed balance will not ship.');

        $resolved = $this->postJson('/api/integration/goods-receipts/'.$receiptId.'/resolve-discrepancy', ['resolution' => 'accept_shortage', 'resolution_reason' => 'Short shipment accepted against supplier confirmation.']);
        $resolved->assertOk()->assertJsonPath('data.discrepancy_status', 'accepted');
        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'discrepancy_status' => 'accepted', 'discrepancy_resolved_by' => $checker->id]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'received', 'receiving_closed' => true]);
        $this->postJson('/api/integration/goods-receipts', ['purchase_order_id' => $order->id, 'date' => now()->toDateString(), 'lines' => [['purchase_order_line_id' => $order->lines()->first()->id, 'quantity' => 1, 'unit_cost' => 5]]])->assertStatus(422);
    }

    public function test_over_receipt_requires_tolerance_reason_and_matching_resolution(): void
    {
        $company = Company::create(['name' => 'Over Receipt Co', 'code' => 'OVER-RECEIPT']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Over Receipt Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Over Receipt Each', 'status' => 1]);
        $category = Category::create(['name' => 'Over Receipt Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Over-delivered item', 'quantity' => 0, 'status' => 1]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-OVER-RECEIPT', 'date' => now()->toDateString(), 'status' => 'approved']);
        $line = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 10, 'received_qty' => 0, 'unit_price' => 5]);
        Sanctum::actingAs($creator, ['purchasing:write']);

        $payload = ['purchase_order_id' => $order->id, 'date' => now()->toDateString(), 'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 11, 'unit_cost' => 5]]];
        $this->postJson('/api/integration/goods-receipts', $payload)->assertStatus(422);

        app(ErpSettingService::class)->put('purchase_over_receipt_tolerance_percent', 10, 'float', $company->id);
        $payload['over_receipt_reason'] = 'Supplier shipped one additional unit under the approved tolerance.';
        $created = $this->postJson('/api/integration/goods-receipts', $payload)->assertCreated();
        $receiptId = (int) $created->json('data.id');

        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $this->postJson('/api/integration/goods-receipts/'.$receiptId.'/approve')->assertOk()->assertJsonPath('data.discrepancy_status', 'open')->assertJsonPath('data.discrepancy_type', 'overage');
        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'discrepancy_reason' => $payload['over_receipt_reason'], 'discrepancy_type' => 'overage']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'partially_received', 'receiving_closed' => false]);
        $this->postJson('/api/integration/goods-receipts', $payload)->assertStatus(422);
        $this->postJson('/api/integration/goods-receipts/'.$receiptId.'/resolve-discrepancy', ['resolution' => 'accept_shortage', 'resolution_reason' => 'Incorrect resolution type.'])->assertStatus(422);
        $this->postJson('/api/integration/goods-receipts/'.$receiptId.'/resolve-discrepancy', ['resolution' => 'accept_overage', 'resolution_reason' => 'Additional unit accepted.'])->assertOk()->assertJsonPath('data.discrepancy_status', 'accepted');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'received', 'receiving_closed' => true]);
    }

    public function test_pending_receipt_can_be_rejected_through_integration_api(): void
    {
        $company = Company::create(['name' => 'Receipt Rejection Co', 'code' => 'RECEIPT-REJECTION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Rejection Supplier', 'is_active' => true]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-REJECTION', 'date' => now()->toDateString(), 'status' => 'approved']);
        $receipt = GoodsReceipt::create(['company_id' => $company->id, 'purchase_order_id' => $order->id, 'grn_no' => 'GRN-REJECTION', 'date' => now()->toDateString(), 'status' => 'pending', 'created_by' => $creator->id]);
        Sanctum::actingAs($checker, ['purchasing:write']);

        $response = $this->postJson('/api/integration/goods-receipts/'.$receipt->id.'/reject', ['rejection_reason' => 'Failed supplier quality documentation.']);

        $response->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejected_by', $checker->id);
        $this->assertDatabaseHas('goods_receipts', ['id' => $receipt->id, 'status' => 'rejected', 'rejection_reason' => 'Failed supplier quality documentation.']);
    }
}
