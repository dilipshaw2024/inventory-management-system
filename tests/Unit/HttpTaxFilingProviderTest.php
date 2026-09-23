<?php

namespace Tests\Unit;

use App\Models\TaxFiling;
use App\Services\Integrations\HttpTaxFilingProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpTaxFilingProviderTest extends TestCase
{
    public function test_http_tax_filing_provider_submits_integrity_bound_payload(): void
    {
        config(['integrations.tax_filing_http_endpoint' => 'https://tax-gateway.example.test/filings', 'integrations.tax_filing_http_token' => 'tax-secret']);
        Http::fake(['https://tax-gateway.example.test/*' => Http::response(['status' => 'accepted', 'reference' => 'TAX-GATEWAY-1'], 200)]);
        $filing = new TaxFiling(['filing_no' => 'TAX-FILE-1', 'external_reference' => 'TAX-1', 'return_type' => 'indirect_tax', 'snapshot_hash' => str_repeat('a', 64), 'snapshot_payload' => ['report' => ['summary' => ['net_tax' => 10]]]]);
        $filing->id = 8;
        $result = (new HttpTaxFilingProvider())->submit($filing);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('TAX-GATEWAY-1', $result['filing_reference']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer tax-secret') && $request->hasHeader('X-ERP-Snapshot-Hash', str_repeat('a', 64)) && $request['snapshot_hash'] === str_repeat('a', 64));
    }

    public function test_http_tax_filing_provider_requires_endpoint(): void
    {
        config(['integrations.tax_filing_http_endpoint' => null]);
        $this->expectExceptionMessage('HTTP tax filing endpoint is not configured');
        (new HttpTaxFilingProvider())->submit(new TaxFiling(['snapshot_payload' => []]));
    }
}
