<?php

namespace Tests\Unit;

use App\Models\Invoice;
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

        $this->assertSame(['generic', 'http', 'test-provider'], $registry->keys());
        $this->assertFalse($registry->supportsSubmission('generic'));
        $this->assertTrue($registry->supportsSubmission('http'));
        $this->assertFalse($registry->supportsSubmission('test-provider'));
    }
}

class FakeEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string { return 'test-provider'; }

    public function prepare(Invoice $invoice): array { return ['invoice_id' => $invoice->id]; }
}
