<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierPaymentProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payment_proposals_are_due_scoped_and_grouped_by_supplier_currency(): void
    {
        $company = Company::create(['name' => 'Payment Proposal Co', 'code' => 'PAY-PROPOSAL', 'base_currency' => 'INR']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Proposal Supplier', 'is_active' => true, 'payment_terms_days' => 15]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Proposal Each', 'code' => 'PROP-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Proposal Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Proposal Item', 'quantity' => 0, 'status' => 1]);
        $order = PurchaseOrder::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-PROPOSAL', 'date' => '2026-09-01', 'status' => 'approved']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 2, 'received_qty' => 2, 'unit_price' => 50]);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'invoice_no' => 'PINV-PROPOSAL', 'supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'status' => 'approved', 'currency_code' => 'INR', 'total_amount' => 100, 'subtotal_amount' => 100]);
        $invoice->lines()->create(['purchase_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);
        $token = $user->createToken('proposal-test', ['accounting:read', 'accounting:write'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/accounting/supplier-payment-proposals?as_of=2026-09-19&due_by=2026-09-19');

        $response->assertOk()->assertJsonPath('summary.invoice_count', 1)->assertJsonPath('summary.proposed_amount', 100)->assertJsonPath('data.0.purchase_invoice_id', $invoice->id)->assertJsonPath('data.0.days_overdue', 9)->assertJsonPath('data.0.currency_code', 'INR')->assertJsonPath('batches.0.invoice_count', 1)->assertJsonPath('batches.0.proposed_amount', 100);

        $run = $this->withToken($token)->postJson('/api/accounting/supplier-payment-runs', ['external_reference' => 'RUN-PROPOSAL-1', 'supplier_id' => $supplier->id, 'invoice_ids' => [$invoice->id], 'payment_date' => '2026-09-19', 'method' => 'bank', 'as_of' => '2026-09-19', 'due_by' => '2026-09-19']);
        $run->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.0.purchase_invoice_id', $invoice->id)->assertJsonPath('data.0.amount', '100.000000')->assertJsonPath('total_amount', 100);
        $this->assertDatabaseHas('supplier_payments', ['external_reference' => 'RUN-PROPOSAL-1:'.$invoice->id, 'status' => 'pending', 'purchase_invoice_id' => $invoice->id, 'created_by' => $user->id]);
        $this->withToken($token)->postJson('/api/accounting/supplier-payment-runs', ['external_reference' => 'RUN-PROPOSAL-1', 'supplier_id' => $supplier->id, 'invoice_ids' => [$invoice->id], 'payment_date' => '2026-09-19', 'method' => 'bank', 'as_of' => '2026-09-19', 'due_by' => '2026-09-19'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->withToken($token)->getJson('/api/accounting/supplier-payment-remittances?external_reference=RUN-PROPOSAL-1')->assertOk()->assertJsonPath('summary.payment_count', 1)->assertJsonPath('summary.total_amount', 100)->assertJsonPath('data.0.invoice_no', 'PINV-PROPOSAL')->assertJsonPath('data.0.status', 'pending');
        $this->withToken($token)->get('/api/accounting/supplier-payment-remittances?external_reference=RUN-PROPOSAL-1&format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('content-disposition');
        Sanctum::actingAs($checker, ['accounting:write']);
        $this->postJson('/api/accounting/supplier-payments/'.$run->json('data.0.id').'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('supplier_payments', ['id' => $run->json('data.0.id'), 'status' => 'approved', 'approved_by' => $checker->id]);
    }
}
