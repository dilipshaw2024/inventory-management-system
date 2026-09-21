<?php

namespace Tests\Unit;

use App\Models\EInvoiceSubmission;
use App\Services\Integrations\HttpEInvoiceProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpEInvoiceProviderTest extends TestCase
{
    public function test_http_provider_submits_hashed_payload_and_returns_provider_metadata(): void
    {
        config([
            'integrations.e_invoice_http_endpoint' => 'https://gateway.example.test/e-invoices',
            'integrations.e_invoice_http_token' => 'secret-token',
            'integrations.e_invoice_http_timeout' => 12,
        ]);
        Http::fake(['https://gateway.example.test/*' => Http::response(['status' => 'accepted', 'reference' => 'GATEWAY-1'], 200)]);
        $submission = new EInvoiceSubmission([
            'provider' => 'http', 'payload_hash' => str_repeat('a', 64),
            'payload' => ['invoice_number' => 'INV-1'],
        ]);
        $submission->id = 42;

        $result = (new HttpEInvoiceProvider())->submit($submission);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('GATEWAY-1', $result['external_reference']);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://gateway.example.test/e-invoices'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request['payload_hash'] === str_repeat('a', 64)
                && $request['payload']['invoice_number'] === 'INV-1';
        });
    }

    public function test_http_provider_reports_gateway_errors_and_missing_configuration(): void
    {
        config(['integrations.e_invoice_http_endpoint' => null]);
        $submission = new EInvoiceSubmission(['provider' => 'http', 'payload' => []]);
        $submission->id = 7;
        $this->expectExceptionMessage('HTTP e-invoice endpoint is not configured');
        (new HttpEInvoiceProvider())->submit($submission);
    }

    public function test_http_provider_retries_transient_gateway_failures_with_idempotency_headers(): void
    {
        config([
            'integrations.e_invoice_http_endpoint' => 'https://gateway.example.test/e-invoices',
            'integrations.e_invoice_http_retries' => 1,
            'integrations.e_invoice_http_retry_sleep' => 0,
        ]);
        Http::fakeSequence()
            ->pushStatus(503)
            ->push(['status' => 'submitted', 'external_reference' => 'GATEWAY-RETRY'], 200);
        $submission = new EInvoiceSubmission([
            'provider' => 'http', 'payload_hash' => str_repeat('b', 64), 'payload' => ['invoice_number' => 'INV-RETRY'],
        ]);
        $submission->id = 43;

        $result = (new HttpEInvoiceProvider())->submit($submission);

        $this->assertSame('submitted', $result['status']);
        $this->assertSame('GATEWAY-RETRY', $result['external_reference']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', 'ERP-EINV-43') && $request->hasHeader('X-ERP-Payload-Hash', str_repeat('b', 64)));
    }
}
