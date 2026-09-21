<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerReceiptProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receipt_proposals_are_due_scoped_and_grouped_by_customer_currency(): void
    {
        $company = Company::create(['name' => 'Receipt Proposal Co', 'code' => 'REC-PROPOSAL', 'base_currency' => 'INR']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Proposal Customer', 'status' => 1, 'credit_days' => 15]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-PROPOSAL', 'date' => '2026-09-01', 'due_date' => '2026-09-10', 'status' => 1, 'currency_code' => 'INR', 'total_amount' => 250]);
        $token = $user->createToken('receipt-proposal-test', ['accounting:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/accounting/customer-receipt-proposals?as_of=2026-09-19&due_by=2026-09-19');

        $response->assertOk()->assertJsonPath('summary.invoice_count', 1)->assertJsonPath('summary.proposed_amount', 250)->assertJsonPath('data.0.invoice_id', $invoice->id)->assertJsonPath('data.0.days_overdue', 9)->assertJsonPath('data.0.currency_code', 'INR')->assertJsonPath('batches.0.invoice_count', 1)->assertJsonPath('batches.0.proposed_amount', 250);

        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);
        $run = $this->postJson('/api/accounting/customer-receipt-runs', ['external_reference' => 'RUN-RECEIPT-1', 'customer_id' => $customer->id, 'invoice_ids' => [$invoice->id], 'payment_date' => '2026-09-19', 'method' => 'bank', 'as_of' => '2026-09-19', 'due_by' => '2026-09-19']);
        $run->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.0.invoice_id', $invoice->id)->assertJsonPath('data.0.paid_amount', '250.000000')->assertJsonPath('total_amount', 250);
        $paymentId = $run->json('data.0.id');
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'approval_status' => 'pending', 'created_by' => $user->id]);
        $this->withToken($user->createToken('receipt-read', ['accounting:read'])->plainTextToken)->getJson('/api/accounting/customer-receipt-proposals?as_of=2026-09-19&due_by=2026-09-19')->assertJsonPath('summary.proposed_amount', 250);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);
        $this->postJson('/api/accounting/customer-payments/'.$paymentId.'/approve')->assertStatus(500);
        Sanctum::actingAs($checker, ['accounting:write']);
        $this->postJson('/api/accounting/customer-payments/'.$paymentId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'approval_status' => 'approved', 'approved_by' => $checker->id]);
        $this->postJson('/api/accounting/customer-receipt-runs', ['external_reference' => 'RUN-RECEIPT-1', 'customer_id' => $customer->id, 'invoice_ids' => [$invoice->id], 'payment_date' => '2026-09-19', 'method' => 'bank', 'as_of' => '2026-09-19', 'due_by' => '2026-09-19'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        Sanctum::actingAs($checker, ['accounting:read', 'accounting:write']);
        $this->getJson('/api/accounting/customer-receipt-proposals?as_of=2026-09-19&due_by=2026-09-19')->assertJsonPath('summary.invoice_count', 0)->assertJsonPath('summary.proposed_amount', 0);
    }
}
