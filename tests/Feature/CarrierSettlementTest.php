<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CarrierSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_carrier_settlement_posts_freight_and_payable_once_and_requires_mappings(): void
    {
        $company = Company::create(['name' => 'Carrier Settlement Co', 'code' => 'CARRIER-SETTLE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Settlement Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-CARRIER-SETTLE', 'date' => now()->toDateString(), 'status' => 'approved']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-CARRIER-SETTLE', 'date' => now()->toDateString(), 'status' => 'approved']);
        Sanctum::actingAs($user, ['accounting:write']);

        $payload = ['amount' => 125.50, 'currency' => 'inr', 'exchange_rate' => 1.2, 'invoice_reference' => 'CARRIER-INV-1', 'settlement_reference' => 'CARRIER-SETTLE-1'];
        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-settlement', $payload)
            ->assertStatus(422)->assertJsonPath('status', 'missing_mapping');
        $this->assertDatabaseCount('journal_entries', 0);

        $freight = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5300', 'name' => 'Freight Expense', 'account_type' => 'expense', 'is_active' => true]);
        $payable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2100', 'name' => 'Accounts Payable', 'account_type' => 'liability', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'freight_expense', 'account_id' => $freight->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'accounts_payable', 'account_id' => $payable->id]);

        $settled = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-settlement', $payload);
        $settled->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('data.carrier_charge_currency', 'INR');
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'carrier_settlement_status' => 'settled', 'carrier_settlement_reference' => 'CARRIER-SETTLE-1', 'carrier_settlement_journal_id' => $settled->json('data.carrier_settlement_journal_id')]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => Delivery::class, 'source_id' => $delivery->id, 'status' => 'posted']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $freight->id, 'debit' => 150.60]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $payable->id, 'credit' => 150.60]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.carrier_settled']);

        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-settlement', $payload)
            ->assertOk()->assertJsonPath('idempotent', true);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-settlement', array_merge($payload, ['amount' => 126]))
            ->assertStatus(422);
    }
}
