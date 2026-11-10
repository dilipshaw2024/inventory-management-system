<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\CurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_rate_requires_provenance_and_can_be_deactivated(): void
    {
        $company = Company::create(['name' => 'FX Governance Co', 'code' => 'FX-GOVERNANCE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2, 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);

        $payload = ['from_currency_id' => $eur->id, 'to_currency_id' => $usd->id, 'rate' => 1.1, 'effective_date' => '2026-09-20', 'source_type' => 'provider'];
        $this->postJson('/api/accounting/exchange-rates', $payload)->assertStatus(422);
        $created = $this->postJson('/api/accounting/exchange-rates', $payload + ['source_reference' => 'ECB-2026-09-20', 'retrieved_at' => '2026-09-20 18:00:00'])
            ->assertCreated()->assertJsonPath('data.source_type', 'provider')->assertJsonPath('data.source_reference', 'ECB-2026-09-20');
        $rateId = (int) $created->json('data.id');
        $this->postJson('/api/accounting/exchange-rates', $payload + ['source_reference' => 'ECB-2026-09-20', 'rate' => 1.11])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $rateId);

        $this->getJson('/api/accounting/exchange-rates?source_type=provider&is_active=1')->assertOk()->assertJsonPath('data.0.id', $rateId);
        $this->postJson('/api/accounting/exchange-rates/'.$rateId.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('status', 'deactivated');
        $this->getJson('/api/accounting/exchange-rates?is_active=1')->assertOk()->assertJsonCount(0, 'data');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No effective exchange rate');
        app(CurrencyConversionService::class)->rate('EUR', 'USD', '2026-09-21');
    }

    public function test_http_exchange_rate_sync_is_provenance_bound_and_idempotent(): void
    {
        $company = Company::create(['name' => 'FX Sync Co', 'code' => 'FX-SYNC']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['code' => 'GBP', 'name' => 'Pound', 'decimal_places' => 2, 'is_active' => true]);
        config(['integrations.exchange_rate_http_endpoint' => 'https://fx.example.test/latest']);
        Http::fake(['https://fx.example.test/*' => Http::response(['base' => 'USD', 'date' => '2026-10-05', 'reference' => 'ECB-2026-10-05', 'rates' => ['EUR' => 0.92, 'GBP' => 0.8]])]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read', 'integration:read']);
        $this->getJson('/api/integration/providers')->assertOk()
            ->assertJsonPath('data.exchange_rate.providers.0.key', 'http')
            ->assertJsonPath('data.exchange_rate.providers.0.supports_sync', true)
            ->assertJsonPath('data.exchange_rate.providers.0.ready', true);

        $this->postJson('/api/accounting/exchange-rates/sync', ['provider' => 'HTTP', 'base_currency' => 'USD', 'effective_date' => '2026-10-05'])
            ->assertCreated()->assertJsonPath('status', 'synchronized')->assertJsonPath('summary.created', 2);
        $this->assertSame(2, ExchangeRate::where('source_type', 'provider')->count());
        $this->postJson('/api/accounting/exchange-rates/sync', ['provider' => 'http', 'base_currency' => 'USD', 'effective_date' => '2026-10-05'])
            ->assertOk()->assertJsonPath('summary.created', 0)->assertJsonPath('summary.duplicates', 2);
    }
}
