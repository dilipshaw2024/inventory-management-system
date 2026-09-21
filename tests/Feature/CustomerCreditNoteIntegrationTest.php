<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerCreditNoteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_credit_note_is_idempotent_and_posts_an_ar_reversal(): void
    {
        $company = Company::create(['name' => 'Customer Credit Co', 'code' => 'CUSTOMER-CREDIT']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Credit Customer', 'is_active' => true]);
        $receivable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200', 'name' => 'Receivable', 'account_type' => 'asset', 'is_active' => true]);
        $returns = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4100', 'name' => 'Sales Returns', 'account_type' => 'expense', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'accounts_receivable', 'account_id' => $receivable->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'sales_returns', 'account_id' => $returns->id]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-CREDIT-1', 'date' => '2026-09-18', 'status' => 1, 'subtotal_amount' => 100, 'total_amount' => 100]);
        Sanctum::actingAs($creator, ['sales:write', 'sales:read']);

        $payload = ['external_reference' => 'CUSTOMER-CREDIT-1', 'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'credit_date' => '2026-09-18', 'subtotal_amount' => 25, 'description' => 'Approved sales allowance'];
        $created = $this->postJson('/api/integration/customer-credit-notes', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('data.total_amount', '25.000000');
        $id = $created->json('data.id');
        $this->postJson('/api/integration/customer-credit-notes', $payload)->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $id);

        Sanctum::actingAs($checker, ['sales:write', 'sales:read']);
        $this->postJson('/api/integration/customer-credit-notes/'.$id.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('customer_credit_notes', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'App\\Models\\CustomerCreditNote', 'source_id' => $id]);
        $this->getJson('/api/integration/customer-credit-notes?status=approved')->assertOk()->assertJsonPath('data.0.id', $id);

        Sanctum::actingAs($checker, ['accounting:read']);
        $this->getJson('/api/accounting/customer-aging?customer_id='.$customer->id.'&as_of=2026-09-18')
            ->assertOk()->assertJsonPath('summary.total', 75);
        $this->getJson('/api/accounting/customers/'.$customer->id.'/statement?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('data.0.type', 'invoice')->assertJsonPath('data.1.type', 'customer_credit_note');
    }
}
