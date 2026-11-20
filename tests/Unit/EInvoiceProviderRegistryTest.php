<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Company;
use App\Services\Integrations\EInvoiceProvider;
use App\Services\Integrations\EInvoiceProviderRegistry;
use Tests\TestCase;

class EInvoiceProviderRegistryTest extends TestCase
{
    public function test_configured_provider_can_be_resolved_through_the_application_registry(): void
    {
        config(['integrations.e_invoice_adapters' => [FakeEInvoiceProvider::class]]);
        $this->app->forgetInstance(EInvoiceProviderRegistry::class);

        $provider = app(EInvoiceProviderRegistry::class)->resolve('test-provider');

        $this->assertInstanceOf(FakeEInvoiceProvider::class, $provider);
        $this->assertSame('test-provider', $provider->key());
    }

    public function test_registry_exposes_keys_and_submission_capability_without_secrets(): void
    {
        config(['integrations.e_invoice_adapters' => [FakeEInvoiceProvider::class]]);
        $this->app->forgetInstance(EInvoiceProviderRegistry::class);

        $registry = app(EInvoiceProviderRegistry::class);

        $this->assertSame(['generic', 'ubl_2_1', 'nfe_55_brazil', 'india_gst_irp', 'mexico_cfdi_4_0', 'saudi_zatca_phase_2', 'uae_peppol_einvoice', 'japan_qualified_invoice', 'south_korea_e_tax_invoice', 'http', 'test-provider'], $registry->keys());
        $this->assertFalse($registry->supportsSubmission('generic'));
        $this->assertTrue($registry->supportsSubmission('http'));
        $this->assertFalse($registry->supportsSubmission('test-provider'));
    }

    public function test_registry_selects_regional_provider_and_preserves_generic_fallback(): void
    {
        $registry = app(EInvoiceProviderRegistry::class);

        $this->assertSame('india_gst_irp', $registry->forCompany(new Company(['country_code' => 'IN']))->key());
        $this->assertSame('nfe_55_brazil', $registry->forCompany(new Company(['country_code' => 'BR']))->key());
        $this->assertSame('mexico_cfdi_4_0', $registry->forCompany(new Company(['country_code' => 'MX']))->key());
        $this->assertSame('saudi_zatca_phase_2', $registry->forCompany(new Company(['country_code' => 'SA']))->key());
        $this->assertSame('uae_peppol_einvoice', $registry->forCompany(new Company(['country_code' => 'AE']))->key());
        $this->assertSame('japan_qualified_invoice', $registry->forCompany(new Company(['country_code' => 'JP']))->key());
        $this->assertSame('south_korea_e_tax_invoice', $registry->forCompany(new Company(['country_code' => 'KR']))->key());
        $this->assertSame('ubl_2_1', $registry->forCompany(new Company(['country_code' => 'AU']))->key());
        $this->assertSame('ubl_2_1', $registry->forCompany(new Company(['country_code' => 'NZ']))->key());
        $this->assertSame('ubl_2_1', $registry->forCompany(new Company(['country_code' => 'SG']))->key());
        $this->assertSame('ubl_2_1', $registry->forCompany(new Company(['country_code' => 'DE']))->key());
        $this->assertSame('generic', $registry->forCompany(new Company(['country_code' => 'US']))->key());
        $this->assertSame('ubl_2_1', $registry->forCompany(new Company(['country_code' => 'IN']), 'ubl_2_1')->key());
    }
}

class FakeEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string { return 'test-provider'; }

    public function prepare(Invoice $invoice): array { return ['invoice_id' => $invoice->id]; }
}
