<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PartyTranslation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartyTranslationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_supplier_translations_are_tenant_scoped_and_replay_safe(): void
    {
        $company = Company::create(['name' => 'Party Translation Co', 'code' => 'PARTY-TRANSLATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Base customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Base supplier', 'is_active' => true]);
        Sanctum::actingAs($user, ['inventory:read', 'sales:write', 'purchasing:write']);

        $customerPayload = ['language_code' => 'es_mx', 'name' => 'Cliente local', 'external_reference' => 'CUSTOMER-ES-MX'];
        $customerTranslation = $this->postJson('/api/inventory/parties/customer/'.$customer->id.'/translations', $customerPayload)
            ->assertCreated()->assertJsonPath('data.language_code', 'es-MX');
        $translationId = $customerTranslation->json('data.id');
        $this->postJson('/api/inventory/parties/customer/'.$customer->id.'/translations', $customerPayload)
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->getJson('/api/inventory/parties/customer/'.$customer->id.'/translations?language_code=es_mx')
            ->assertOk()->assertJsonPath('data.0.name', 'Cliente local');
        $this->assertSame('Cliente local', $customer->fresh()->localizedName('es-MX'));

        $supplierTranslation = $this->postJson('/api/inventory/parties/supplier/'.$supplier->id.'/translations', ['language_code' => 'de-DE', 'name' => 'Lokaler Lieferant'])
            ->assertCreated();
        $this->patchJson('/api/inventory/parties/supplier/'.$supplier->id.'/translations/'.$supplierTranslation->json('data.id'), ['name' => 'Aktualisierter Lieferant'])
            ->assertOk()->assertJsonPath('data.name', 'Aktualisierter Lieferant');
        $this->postJson('/api/inventory/parties/customer/'.$customer->id.'/translations/'.$translationId.'/deactivate')
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('party_translations', ['company_id' => $company->id, 'party_type' => 'customer', 'party_id' => $customer->id, 'is_active' => 0]);
        $this->assertSame('Base customer', $customer->fresh()->localizedName('es-MX'));
    }
}
