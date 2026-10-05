<?php

namespace Tests\Unit;

use App\Models\TaxFiling;
use App\Services\Integrations\TaxFilingProvider;
use App\Services\Integrations\TaxFilingProviderRegistry;
use Tests\TestCase;

class TaxFilingProviderRegistryTest extends TestCase
{
    public function test_configured_provider_is_resolvable(): void
    {
        config(['integrations.tax_filing_adapters' => [FakeTaxFilingProvider::class]]);
        $this->app->forgetInstance(TaxFilingProviderRegistry::class);

        $provider = app(TaxFilingProviderRegistry::class)->resolve('test-tax');

        $this->assertInstanceOf(FakeTaxFilingProvider::class, $provider);
        $this->assertSame(['http', 'test-tax'], app(TaxFilingProviderRegistry::class)->keys());
    }
}

class FakeTaxFilingProvider implements TaxFilingProvider
{
    public function key(): string { return 'test-tax'; }
    public function submit(TaxFiling $filing): array { return ['status' => 'submitted', 'filing_reference' => 'TEST-'.$filing->id, 'response' => []]; }
}
