<?php

namespace Tests\Unit;

use App\Services\Integrations\StatutoryRemittanceProvider;
use App\Services\Integrations\StatutoryRemittanceProviderRegistry;
use Tests\TestCase;

class StatutoryRemittanceProviderRegistryTest extends TestCase
{
    public function test_configured_provider_is_discoverable_and_resolvable(): void
    {
        config(['integrations.statutory_remittance_adapters' => [FakeStatutoryRemittanceProvider::class]]);
        $this->app->forgetInstance(StatutoryRemittanceProviderRegistry::class);

        $registry = app(StatutoryRemittanceProviderRegistry::class);
        $this->assertInstanceOf(FakeStatutoryRemittanceProvider::class, $registry->resolve('test-remit'));
        $this->assertSame(['http', 'test-remit'], $registry->keys());
    }
}

class FakeStatutoryRemittanceProvider implements StatutoryRemittanceProvider
{
    public function key(): string { return 'test-remit'; }
    public function submit(array $package): array { return ['status' => 'submitted', 'reference' => 'TEST-1', 'response' => []]; }
}
