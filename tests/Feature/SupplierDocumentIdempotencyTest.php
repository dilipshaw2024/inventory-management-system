<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\SupplierCreditNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_claim_replay_returns_the_original_claim(): void
    {
        $company = Company::create(['name' => 'Claim Replay Co', 'code' => 'CLAIM-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Claim Replay Supplier', 'is_active' => true]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-CLAIM-REPLAY', 'date' => '2026-09-20', 'status' => 'received']);
        $receipt = GoodsReceipt::create(['company_id' => $company->id, 'purchase_order_id' => $order->id, 'grn_no' => 'GRN-CLAIM-REPLAY', 'date' => '2026-09-20', 'status' => 'approved']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-CLAIM-REPLAY', 'invoice_date' => '2026-09-20', 'status' => 'approved', 'subtotal_amount' => 100, 'total_amount' => 100]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = ['external_reference' => 'CLAIM-REPLAY-1', 'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id, 'goods_receipt_id' => $receipt->id, 'claim_date' => '2026-09-20', 'reason_code' => 'short_shipment', 'claim_amount' => 25];

        $created = $this->postJson('/api/integration/supplier-claims', $payload);
        $created->assertCreated()->assertJsonPath('status', 'open');
        $replayed = $this->postJson('/api/integration/supplier-claims', $payload + ['claim_amount' => 99]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, SupplierClaim::where('company_id', $company->id)->count());
    }

    public function test_supplier_credit_note_replay_returns_the_original_note(): void
    {
        $company = Company::create(['name' => 'Credit Replay Co', 'code' => 'CREDIT-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Credit Replay Supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-CREDIT-REPLAY', 'invoice_date' => '2026-09-20', 'status' => 'approved', 'subtotal_amount' => 100, 'total_amount' => 100]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = ['external_reference' => 'CREDIT-REPLAY-1', 'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id, 'credit_date' => '2026-09-20', 'subtotal_amount' => 25];

        $created = $this->postJson('/api/integration/supplier-credit-notes', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending');
        $replayed = $this->postJson('/api/integration/supplier-credit-notes', $payload + ['subtotal_amount' => 99]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, SupplierCreditNote::where('company_id', $company->id)->count());
    }
}
