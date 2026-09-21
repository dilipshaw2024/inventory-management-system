<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SalesQuotation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesQuotationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_quotation_can_be_created_approved_converted_and_replayed_through_integration_api(): void
    {
        $company = Company::create(['name' => 'Sales Quotation Integration Co', 'code' => 'SQ-INTEGRATION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Quotation Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Quotation Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Quotation Each', 'code' => 'SQ-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Quotation Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Quotation Product', 'quantity' => 0, 'status' => 1]);
        Sanctum::actingAs($creator, ['sales:read', 'sales:write']);

        $created = $this->postJson('/api/integration/sales-quotations', [
            'external_reference' => 'SQ-EXT-1', 'customer_id' => $customer->id, 'quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50, 'discount_amount' => 5]],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('data.lines.0.product_id', $product->id);
        $quotationId = $created->json('data.id');

        $duplicate = $this->postJson('/api/integration/sales-quotations', [
            'external_reference' => 'SQ-EXT-1', 'customer_id' => $customer->id, 'quote_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50, 'discount_amount' => 5]],
        ]);
        $duplicate->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $quotationId);
        $this->getJson('/api/integration/sales-quotations?status=submitted')->assertOk()->assertJsonPath('data.0.id', $quotationId);

        $invitation = $this->postJson('/api/integration/sales-quotations/'.$quotationId.'/customer-portal-invitation', [])->assertOk()->assertJsonPath('data.customer_id', $customer->id);
        $portalToken = $invitation->json('data.token');
        $this->getJson('/api/customer-portal/quotations/'.$portalToken)->assertOk()->assertJsonPath('data.quotation.id', $quotationId);
        $this->postJson('/api/customer-portal/quotations/'.$portalToken.'/accept')->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/integration/sales-quotations/'.$quotationId.'/customer-portal-invitation/revoke')->assertOk()->assertJsonPath('status', 'revoked');
        $this->assertDatabaseHas('sales_quotations', ['id' => $quotationId, 'customer_response_status' => 'accepted']);

        $declined = $this->postJson('/api/integration/sales-quotations', [
            'external_reference' => 'SQ-PORTAL-DECLINED', 'customer_id' => $customer->id, 'quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25, 'discount_amount' => 0]],
        ])->assertCreated();
        $declinedInvitation = $this->postJson('/api/integration/sales-quotations/'.$declined->json('data.id').'/customer-portal-invitation', [])->assertOk();
        $this->postJson('/api/customer-portal/quotations/'.$declinedInvitation->json('data.token').'/decline', ['reason' => 'Customer postponed the purchase.'])->assertOk()->assertJsonPath('status', 'declined');

        Sanctum::actingAs($checker, ['sales:read', 'sales:write']);
        $this->postJson('/api/integration/sales-quotations/'.$quotationId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/integration/sales-quotations/'.$declined->json('data.id').'/approve')->assertStatus(422);
        $converted = $this->postJson('/api/integration/sales-quotations/'.$quotationId.'/convert');
        $converted->assertCreated()->assertJsonPath('status', 'sales_order_created')->assertJsonPath('data.customer_id', $customer->id)->assertJsonPath('data.lines.0.discount_amount', '5.000000');
        $this->assertDatabaseHas('sales_quotations', ['id' => $quotationId, 'status' => 'converted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales_quotation.converted']);

        SalesQuotation::create(['company_id' => $company->id, 'quote_no' => 'SQ-EXPIRED', 'customer_id' => $customer->id, 'quote_date' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDay()->toDateString(), 'status' => 'submitted', 'created_by' => $creator->id]);
        $this->artisan('erp:sales:expire-quotations')->assertExitCode(0);
        $this->assertDatabaseHas('sales_quotations', ['quote_no' => 'SQ-EXPIRED', 'status' => 'expired']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales_quotation.expired']);
    }
}
