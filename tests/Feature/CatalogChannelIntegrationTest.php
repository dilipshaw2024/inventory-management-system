<?php

namespace Tests\Feature;

use App\Models\CatalogChannel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_channels_are_company_scoped_idempotent_and_lifecycle_managed(): void
    {
        $company = Company::create(['name' => 'Catalog Channel Co', 'code' => 'CATALOG-CHANNEL']);
        $otherCompany = Company::create(['name' => 'Other Catalog Co', 'code' => 'OTHER-CATALOG']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $payload = [
            'code' => 'Web_Store', 'name' => 'Web Storefront', 'currency_code' => 'usd',
            'locale' => 'en_US', 'settings' => ['price_mode' => 'gross'], 'external_reference' => 'CHANNEL-WEB-1',
        ];
        $created = $this->postJson('/api/inventory/catalog-channels', $payload);
        $created->assertCreated()->assertJsonPath('data.code', 'web_store')->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.locale', 'en-us');
        $id = $created->json('data.id');

        $this->postJson('/api/inventory/catalog-channels', $payload)->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $id);
        $this->getJson('/api/inventory/catalog-channels?code=WEB_STORE')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/inventory/catalog-channels/'.$id, ['name' => 'Web Storefront Updated', 'is_active' => true])->assertOk()->assertJsonPath('data.name', 'Web Storefront Updated');
        $this->postJson('/api/inventory/catalog-channels/'.$id.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/inventory/products?catalog=1&channel=web_store')->assertStatus(422);
        $this->assertDatabaseHas('catalog_channels', ['id' => $id, 'company_id' => $company->id, 'code' => 'web_store', 'is_active' => 0]);

        Sanctum::actingAs($otherUser, ['inventory:read', 'inventory:write']);
        $this->getJson('/api/inventory/catalog-channels')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/inventory/catalog-channels/'.$id, ['name' => 'Cross tenant'])->assertNotFound();
        $this->assertSame(1, CatalogChannel::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }
}
