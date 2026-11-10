<?php

namespace Tests\Unit;

use App\Services\Integrations\ExchangeRateProviderRegistry;
use Tests\Support\FakeExchangeRateProvider;
use Tests\TestCase;

class ExchangeRateProviderRegistryTest extends TestCase
{
    public function test_configured_exchange_rate_provider_is_resolvable(): void
    {
        config(['integrations.exchange_rate_adapters' => [FakeExchangeRateProvider::class]]);
        $this->app->forgetInstance(ExchangeRateProviderRegistry::class);
        $registry = app(ExchangeRateProviderRegistry::class);

        $this->assertSame(['http', 'test-fx'], $registry->keys());
        $this->assertInstanceOf(FakeExchangeRateProvider::class, $registry->resolve('TEST-FX'));
    }
}
