<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierPayablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_claim_can_follow_a_goods_receipt_through_audited_lifecycle(): void
    {
        $company = Company::create(['name' => 'Supplier Claim Co', 'code' => 'SUP-CLAIM']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Claim Supplier', 'is_active' => true]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-SUP-CLAIM', 'date' => now()->toDateString(), 'status' => 'received']);
        $receipt = GoodsReceipt::create(['company_id' => $company->id, 'purchase_order_id' => $order->id, 'grn_no' => 'GRN-SUP-CLAIM', 'date' => now()->toDateString(), 'status' => 'approved']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-SUP-CLAIM', 'invoice_date' => now()->toDateString(), 'status' => 'approved', 'subtotal_amount' => 200, 'total_amount' => 200]);

        Sanctum::actingAs($creator, ['purchasing:write']);
        $created = $this->postJson('/api/integration/supplier-claims', [
            'external_reference' => 'CLAIM-EXT-1', 'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id, 'goods_receipt_id' => $receipt->id,
            'claim_date' => now()->toDateString(), 'reason_code' => 'short_shipment', 'description' => 'Short shipment claim', 'claim_amount' => 125.50,
        ]);
        $created->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('data.goods_receipt_id', $receipt->id)->assertJsonPath('data.purchase_invoice_id', $invoice->id);
        $claimId = (int) $created->json('data.id');

        $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'accepted', 'resolution_notes' => 'Creator must not self-approve.'])->assertStatus(422);

        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'submitted'])->assertOk()->assertJsonPath('status', 'submitted');
        $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'accepted', 'resolution_notes' => 'Supplier accepted the shortage.'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'partially_settled', 'settled_amount' => 50, 'settlement_reference' => 'CN-PART-1', 'resolution_notes' => 'Partial credit received.'])->assertOk()->assertJsonPath('status', 'partially_settled');
        $settled = $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'settled', 'settled_amount' => 125.50, 'settlement_reference' => 'CN-FULL-1', 'resolution_notes' => 'Final credit settlement confirmed.']);
        $settled->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('data.settled_amount', '125.500000')->assertJsonPath('data.settlement_reference', 'CN-FULL-1');
        $this->patchJson('/api/integration/supplier-claims/'.$claimId, ['status' => 'settled', 'settled_amount' => 125.50, 'settlement_reference' => 'CN-FULL-1', 'resolution_notes' => 'Replay final credit settlement.'])
            ->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('idempotent', true);

        $feed = $this->getJson('/api/integration/supplier-claims?status=settled&supplier_id='.$supplier->id);
        $feed->assertOk()->assertJsonPath('data.0.id', $claimId)->assertJsonPath('data.0.claim_amount', '125.500000');
        $this->assertDatabaseHas('supplier_claims', ['id' => $claimId, 'status' => 'settled', 'settled_amount' => 125.50, 'settlement_reference' => 'CN-FULL-1', 'settled_by' => $checker->id]);
        $assessment = app(SupplierPayablesService::class)->assess($supplier, now()->toDateString());
        $this->assertSame(74.5, $assessment['outstanding']);
    }

    public function test_supplier_credit_note_can_be_approved_against_a_purchase_invoice(): void
    {
        $company = Company::create(['name' => 'Credit Note Co', 'code' => 'CREDIT-NOTE']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Credit Note Supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-CREDIT-NOTE', 'invoice_date' => now()->toDateString(), 'status' => 'approved', 'subtotal_amount' => 200, 'total_amount' => 200]);

        Sanctum::actingAs($creator, ['purchasing:write']);
        $created = $this->postJson('/api/integration/supplier-credit-notes', [
            'external_reference' => 'CN-EXT-1', 'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id,
            'credit_date' => now()->toDateString(), 'subtotal_amount' => 25, 'description' => 'Approved supplier credit',
        ]);
        $created->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('data.purchase_invoice_id', $invoice->id);

        Sanctum::actingAs($checker, ['purchasing:write']);
        $approved = $this->postJson('/api/integration/supplier-credit-notes/'.$created->json('data.id').'/approve');
        $approved->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('data.total_amount', '25.000000');
        $this->assertDatabaseHas('supplier_credit_notes', ['external_reference' => 'CN-EXT-1', 'status' => 'approved']);
        $assessment = app(SupplierPayablesService::class)->assess($supplier, now()->toDateString());
        $this->assertSame(175.0, $assessment['outstanding']);
    }
}
