<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
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
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Settings Branch', 'code' => 'SETTINGS-BRANCH']);
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
            'carrier_sla_hours' => ['Carrier One' => 36, 'Carrier Two' => 72],
            'branch_carrier_sla_hours' => [(string) $branch->id => ['Carrier One' => 24]],
            'sla_calendar' => ['weekend_days' => [0, 6], 'holidays' => ['2026-10-02'], 'shift_start' => '08:00', 'shift_end' => '17:00'],
            'branch_sla_calendars' => [(string) $branch->id => ['weekend_days' => [0, 6], 'holidays' => [], 'shift_start' => '07:00', 'shift_end' => '16:00']],
        ]);
        $updated->assertOk()
            ->assertJsonPath('data.abc_a_threshold_percent', 70)
            ->assertJsonPath('data.abc_b_threshold_percent', 90)
            ->assertJsonPath('data.warehouse_capacity_alert_percent', 85)
            ->assertJsonPath('data.slow_moving_days', 120)
            ->assertJsonPath('data.dead_stock_days', 240)
            ->assertJsonPath('data.expiry_alert_days', 60)
            ->assertJsonPath('data.carrier_sla_hours.Carrier One', 36)
            ->assertJsonPath('data.carrier_sla_hours.Carrier Two', 72)
            ->assertJsonPath('data.branch_carrier_sla_hours.'.$branch->id.'.Carrier One', 24)
            ->assertJsonPath('data.sla_calendar.shift_start', '08:00')
            ->assertJsonPath('data.sla_calendar.shift_end', '17:00')
            ->assertJsonPath('data.branch_sla_calendars.'.$branch->id.'.shift_start', '07:00');

        $this->patchJson('/api/accounting/settings', ['abc_a_threshold_percent' => 95, 'abc_b_threshold_percent' => 90])
            ->assertStatus(422);
    }

    public function test_approved_customer_refund_can_be_settled_idempotently_and_filtered(): void
    {
        $company = Company::create(['name' => 'Refund Settlement Co', 'code' => 'REFUND-SETTLE']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Settlement Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Settlement Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-REFUND-SETTLE', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Settlement Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Returned item', 'quantity' => 0, 'sales_price' => 50, 'purchase_price' => 20, 'status' => 1]);
        $return = InventoryReturn::create([
            'company_id' => $company->id, 'return_no' => 'RET-REFUND-SETTLE', 'return_type' => 'sales',
            'customer_id' => $customer->id, 'date' => now()->toDateString(), 'reason_code' => 'customer_return',
            'status' => 'approved', 'created_by' => $creator->id,
        ]);
        InventoryReturnLine::create(['return_id' => $return->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 20, 'unit_price' => 50, 'tax_rate' => 0]);

        Sanctum::actingAs($creator, ['accounting:write']);
        $refundId = $this->postJson('/api/accounting/customer-refunds', [
            'inventory_return_id' => $return->id, 'customer_id' => $customer->id, 'amount' => 25, 'method' => 'bank',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($checker, ['accounting:write']);
        $this->postJson('/api/accounting/customer-refunds/'.$refundId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/accounting/customer-refunds/'.$refundId.'/settle', ['settlement_reference' => 'BANK-REFUND-1001'])
            ->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('data.settlement_status', 'settled');
        $this->postJson('/api/accounting/customer-refunds/'.$refundId.'/settle', ['settlement_reference' => 'BANK-REFUND-1001'])
            ->assertOk()->assertJsonPath('idempotent', true);

        Sanctum::actingAs($checker, ['accounting:read']);
        $this->getJson('/api/accounting/customer-refunds?settlement_status=settled')
            ->assertOk()->assertJsonPath('data.0.id', $refundId)->assertJsonPath('data.0.settlement_reference', 'BANK-REFUND-1001');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_refund.settled', 'auditable_id' => $refundId]);
    }
}
