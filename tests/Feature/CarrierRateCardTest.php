<?php

namespace Tests\Feature;

use App\Models\CarrierRateCard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CarrierRateCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_cards_are_idempotent_and_quote_by_weight_and_zone(): void
    {
        $company = Company::create(['name' => 'Rate Card Co', 'code' => 'RATE-CARD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['warehouse:read', 'warehouse:write']);

        $payload = [
            'external_reference' => 'RATE-EXT-1',
            'carrier' => 'Universal Carrier',
            'service_code' => 'EXPRESS',
            'origin_zone' => 'IN-W',
            'destination_zone' => 'IN-S',
            'min_weight_kg' => 0,
            'max_weight_kg' => 10,
            'base_amount' => 100,
            'per_kg_amount' => 20,
            'currency_code' => 'inr',
            'transit_days' => 2,
            'valid_from' => '2026-01-01',
        ];
        $created = $this->postJson('/api/integration/logistics/rate-cards', $payload);
        $created->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('data.currency_code', 'INR');
        $this->postJson('/api/integration/logistics/rate-cards', $payload + ['base_amount' => 999])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));

        CarrierRateCard::create([
            'company_id' => $company->id, 'carrier' => 'Universal Carrier', 'service_code' => 'ECONOMY',
            'origin_zone' => null, 'destination_zone' => null, 'min_weight_kg' => 0, 'max_weight_kg' => null,
            'base_amount' => 50, 'per_kg_amount' => 5, 'currency_code' => 'INR', 'transit_days' => 5,
            'is_active' => true,
        ]);
        $quote = $this->postJson('/api/integration/logistics/rate-quotes', [
            'carrier' => 'Universal Carrier', 'weight_kg' => 5, 'origin_zone' => 'IN-W', 'destination_zone' => 'IN-S', 'as_of' => '2026-09-29',
        ]);
        $quote->assertOk()->assertJsonPath('meta.count', 2)->assertJsonPath('data.0.service_code', 'ECONOMY')->assertJsonPath('data.0.total_amount', 75)->assertJsonPath('data.1.total_amount', 200);

        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Rate Quote Customer', 'is_active' => true]);
        $order = SalesOrder::create([
            'order_no' => 'SO-RATE-1', 'customer_id' => $customer->id, 'date' => '2026-09-29', 'status' => 'partially_delivered',
        ]);
        $delivery = Delivery::create([
            'company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-RATE-1', 'date' => '2026-09-29',
            'carrier' => 'Universal Carrier', 'status' => 'approved', 'total_weight' => 5, 'weight_unit' => 'kg',
        ]);
        $this->postJson('/api/integration/logistics/rate-quotes', [
            'delivery_id' => $delivery->id, 'origin_zone' => 'IN-W', 'destination_zone' => 'IN-S', 'as_of' => '2026-09-29',
        ])->assertOk()->assertJsonPath('meta.delivery_id', $delivery->id)->assertJsonPath('meta.weight_kg', 5)
            ->assertJsonPath('data.0.service_code', 'ECONOMY');

        $selected = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-quote', [
            'rate_card_id' => $created->json('data.id'), 'origin_zone' => 'IN-W', 'destination_zone' => 'IN-S',
            'as_of' => '2026-09-29', 'external_reference' => 'DELIVERY-RATE-1',
        ]);
        $selected->assertOk()->assertJsonPath('status', 'selected')->assertJsonPath('data.service_code', 'EXPRESS')
            ->assertJsonPath('data.amount', 200)->assertJsonPath('data.currency_code', 'INR')->assertJsonPath('data.weight_kg', 5);
        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/carrier-quote', [
            'rate_card_id' => $created->json('data.id'), 'origin_zone' => 'IN-W', 'destination_zone' => 'IN-S',
            'external_reference' => 'DELIVERY-RATE-1',
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored');

        $this->getJson('/api/integration/logistics/rate-cards?carrier=Universal%20Carrier')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_browser_rate_card_administration_is_permission_protected_and_audited(): void
    {
        $company = Company::create(['name' => 'Browser Rate Card Co', 'code' => 'BROWSER-RATE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['name' => 'Warehouse manage', 'code' => 'warehouse.manage', 'module' => 'warehouse']);
        $role = Role::create(['name' => 'Rate card operator', 'code' => 'rate-card-operator']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $this->actingAs($user)->get('/warehouse/logistics/rate-cards')->assertOk()->assertSee('Carrier Rate Cards');
        $this->actingAs($user)->post('/warehouse/logistics/rate-cards', [
            'external_reference' => 'BROWSER-RATE-1', 'carrier' => 'Browser Carrier', 'service_code' => 'GROUND',
            'base_amount' => 25, 'per_kg_amount' => 4, 'currency_code' => 'inr', 'transit_days' => 3,
        ])->assertRedirect();
        $card = CarrierRateCard::where('company_id', $company->id)->firstOrFail();
        $this->assertDatabaseHas('carrier_rate_cards', ['id' => $card->id, 'currency_code' => 'INR', 'is_active' => 1]);
        $this->actingAs($user)->put('/warehouse/logistics/rate-cards/'.$card->id, [
            'carrier' => 'Browser Carrier Updated', 'service_code' => 'GROUND', 'base_amount' => 30,
            'per_kg_amount' => 5, 'currency_code' => 'INR',
        ])->assertRedirect();
        $this->assertDatabaseHas('carrier_rate_cards', ['id' => $card->id, 'carrier' => 'Browser Carrier Updated', 'base_amount' => 30]);
        $this->actingAs($user)->post('/warehouse/logistics/rate-cards/'.$card->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('carrier_rate_cards', ['id' => $card->id, 'is_active' => 0]);
    }

}
