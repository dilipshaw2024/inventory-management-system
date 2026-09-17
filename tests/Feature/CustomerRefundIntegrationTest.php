<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerRefundIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_refund_creation_is_idempotent_by_company_external_reference(): void
    {
        $company = Company::create(['name' => 'Refund Integration Co', 'code' => 'REFUND-INT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Refund Customer', 'is_active' => true]);
        $return = InventoryReturn::create([
            'company_id' => $company->id, 'return_no' => 'RET-REFUND-INT', 'return_type' => 'sales',
            'customer_id' => $customer->id, 'date' => now()->toDateString(), 'reason_code' => 'customer_return',
            'status' => 'approved', 'created_by' => $user->id,
        ]);

        Sanctum::actingAs($user, ['accounting:write']);
        $payload = [
            'external_reference' => 'REFUND-EXT-1', 'inventory_return_id' => $return->id,
            'customer_id' => $customer->id, 'amount' => 25, 'method' => 'bank',
        ];
        $created = $this->postJson('/api/accounting/customer-refunds', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.external_reference', 'REFUND-EXT-1');

        $replayed = $this->postJson('/api/accounting/customer-refunds', $payload);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('customer_refunds', 1);
    }

    public function test_accounting_settings_api_is_company_scoped_and_validates_abc_thresholds(): void
    {
        $company = Company::create(['name' => 'Settings Integration Co', 'code' => 'SETTINGS-INT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);

        $this->getJson('/api/accounting/settings')
            ->assertOk()
            ->assertJsonPath('data.abc_a_threshold_percent', 80)
            ->assertJsonPath('data.abc_b_threshold_percent', 95);

        $updated = $this->patchJson('/api/accounting/settings', [
            'abc_a_threshold_percent' => 70,
            'abc_b_threshold_percent' => 90,
            'warehouse_capacity_alert_percent' => 85,
            'slow_moving_days' => 120,
            'dead_stock_days' => 240,
            'expiry_alert_days' => 60,
        ]);
        $updated->assertOk()
            ->assertJsonPath('data.abc_a_threshold_percent', 70)
            ->assertJsonPath('data.abc_b_threshold_percent', 90)
            ->assertJsonPath('data.warehouse_capacity_alert_percent', 85)
            ->assertJsonPath('data.slow_moving_days', 120)
            ->assertJsonPath('data.dead_stock_days', 240)
            ->assertJsonPath('data.expiry_alert_days', 60);

        $this->patchJson('/api/accounting/settings', ['abc_a_threshold_percent' => 95, 'abc_b_threshold_percent' => 90])
            ->assertStatus(422);
    }
}
