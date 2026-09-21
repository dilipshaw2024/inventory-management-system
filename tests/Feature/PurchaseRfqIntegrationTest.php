<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseRfqIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfq_can_be_created_quoted_awarded_and_replayed_through_integration_api(): void
    {
        $company = Company::create(['name' => 'RFQ Integration Co', 'code' => 'RFQ-INTEGRATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'RFQ Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'RFQ Each', 'code' => 'RFQ-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'RFQ Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'RFQ Product', 'quantity' => 0, 'status' => 1]);
        $requisition = PurchaseRequisition::create(['company_id' => $company->id, 'requisition_no' => 'PR-RFQ-LINK', 'requested_date' => now()->toDateString(), 'required_date' => now()->addDays(7)->toDateString(), 'suggested_supplier_id' => $supplier->id, 'status' => 'approved', 'approved_by' => $checker->id, 'approved_at' => now(), 'created_by' => $user->id]);
        PurchaseRequisitionLine::create(['purchase_requisition_id' => $requisition->id, 'product_id' => $product->id, 'requested_qty' => 4, 'estimated_unit_price' => 10]);
        Sanctum::actingAs($user, ['purchasing:read', 'purchasing:write']);

        $created = $this->postJson('/api/integration/rfqs', [
            'external_reference' => 'RFQ-EXT-1', 'purchase_requisition_id' => $requisition->id, 'issue_date' => now()->toDateString(), 'response_due' => now()->addDays(5)->toDateString(),
            'supplier_ids' => [$supplier->id], 'lines' => [['product_id' => $product->id, 'requested_qty' => 4, 'notes' => 'Quote standard grade']],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('data.purchase_requisition_id', $requisition->id)->assertJsonPath('data.lines.0.product_id', $product->id);
        $rfqId = $created->json('data.id');
        $supplierResponseId = $created->json('data.suppliers.0.id');
        $lineId = $created->json('data.lines.0.id');

        $invitation = $this->postJson('/api/integration/rfqs/'.$rfqId.'/suppliers/'.$supplier->id.'/portal-invitation', [])->assertOk()->assertJsonPath('data.supplier_id', $supplier->id);
        $portalToken = $invitation->json('data.token');
        $this->getJson('/api/supplier-portal/rfqs/'.$portalToken)->assertOk()->assertJsonPath('data.rfq_supplier_id', $supplierResponseId)->assertJsonPath('data.lines.0.id', $lineId);
        $this->postJson('/api/supplier-portal/rfqs/'.$portalToken.'/quote', ['lines' => [['purchase_rfq_line_id' => $lineId, 'unit_price' => 12.5, 'lead_days' => 3]]])->assertOk()->assertJsonPath('status', 'quoted');

        $duplicate = $this->postJson('/api/integration/rfqs', [
            'external_reference' => 'RFQ-EXT-1', 'issue_date' => now()->toDateString(), 'supplier_ids' => [$supplier->id],
            'lines' => [['product_id' => $product->id, 'requested_qty' => 4]],
        ]);
        $duplicate->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $rfqId);

        $quoted = $this->postJson('/api/integration/rfqs/'.$rfqId.'/quote', [
            'rfq_supplier_id' => $supplierResponseId, 'lines' => [['purchase_rfq_line_id' => $lineId, 'unit_price' => 12.5, 'lead_days' => 3]],
        ]);
        $quoted->assertOk()->assertJsonPath('status', 'quoted')->assertJsonPath('data.status', 'quoted');

        $feed = $this->getJson('/api/integration/rfqs?status=submitted');
        $feed->assertOk()->assertJsonPath('data.0.id', $rfqId)->assertJsonPath('data.0.suppliers.0.quotations.0.unit_price', '12.500000');

        $awarded = $this->postJson('/api/integration/rfqs/'.$rfqId.'/award', ['rfq_supplier_id' => $supplierResponseId]);
        $awarded->assertCreated()->assertJsonPath('status', 'purchase_order_created')->assertJsonPath('data.supplier_id', $supplier->id)->assertJsonPath('data.lines.0.unit_price', '12.500000');
        $this->assertDatabaseHas('purchase_rfqs', ['id' => $rfqId, 'status' => 'closed']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $awarded->json('data.id'), 'supplier_id' => $supplier->id, 'status' => 'submitted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_rfq.awarded']);

        $closed = $this->postJson('/api/integration/rfqs', ['external_reference' => 'RFQ-EXT-CLOSE', 'issue_date' => now()->toDateString(), 'supplier_ids' => [$supplier->id], 'lines' => [['product_id' => $product->id, 'requested_qty' => 1]]]);
        $this->postJson('/api/integration/rfqs/'.$closed->json('data.id').'/close')->assertOk()->assertJsonPath('status', 'closed');
        $rejected = $this->postJson('/api/integration/rfqs', ['external_reference' => 'RFQ-EXT-REJECT', 'issue_date' => now()->toDateString(), 'supplier_ids' => [$supplier->id], 'lines' => [['product_id' => $product->id, 'requested_qty' => 1]]]);
        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $this->postJson('/api/integration/rfqs/'.$rejected->json('data.id').'/reject', ['rejection_reason' => 'Budget was deferred.'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_rfq.closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_rfq.rejected']);

        $portalRfq = $this->postJson('/api/integration/rfqs', ['external_reference' => 'RFQ-PORTAL-DECLINE', 'issue_date' => now()->toDateString(), 'supplier_ids' => [$supplier->id], 'lines' => [['product_id' => $product->id, 'requested_qty' => 1]]])->assertCreated();
        $portalInvitation = $this->postJson('/api/integration/rfqs/'.$portalRfq->json('data.id').'/suppliers/'.$supplier->id.'/portal-invitation', [])->assertOk();
        $this->postJson('/api/supplier-portal/rfqs/'.$portalInvitation->json('data.token').'/decline', ['reason' => 'Capacity is unavailable this month.'])->assertOk()->assertJsonPath('status', 'declined');
        $this->postJson('/api/supplier-portal/rfqs/'.$portalInvitation->json('data.token').'/quote', ['lines' => [['purchase_rfq_line_id' => $portalRfq->json('data.lines.0.id'), 'unit_price' => 9]]])->assertStatus(422);
        $this->postJson('/api/integration/rfqs/'.$portalRfq->json('data.id').'/suppliers/'.$supplier->id.'/portal-invitation/revoke')->assertOk()->assertJsonPath('status', 'revoked');
        $this->assertDatabaseHas('purchase_rfq_suppliers', ['id' => $portalInvitation->json('data.rfq_supplier_id'), 'status' => 'declined', 'notes' => 'Capacity is unavailable this month.']);

        PurchaseRfq::create(['company_id' => $company->id, 'rfq_no' => 'RFQ-OVERDUE', 'issue_date' => now()->subDays(5)->toDateString(), 'response_due' => now()->subDay()->toDateString(), 'status' => 'submitted', 'created_by' => $user->id]);
        $this->artisan('erp:procurement:close-overdue-rfqs')->assertExitCode(0);
        $this->assertDatabaseHas('purchase_rfqs', ['rfq_no' => 'RFQ-OVERDUE', 'status' => 'closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_rfq.closed_overdue']);
    }
}
