<?php

namespace Tests\Unit;

use App\Services\Integrations\HttpStatutoryRemittanceProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpStatutoryRemittanceProviderTest extends TestCase
{
    public function test_http_provider_submits_hash_bound_package_with_idempotency_key(): void
    {
        config(['integrations.statutory_remittance_http_endpoint' => 'https://payroll-gateway.example.test/remit', 'integrations.statutory_remittance_http_token' => 'payroll-secret']);
        Http::fake(['https://payroll-gateway.example.test/*' => Http::response(['status' => 'accepted', 'reference' => 'PAY-1'], 200)]);
        $package = ['schema' => 'erp.hr.statutory-remittance.v1', 'company_id' => 9, 'data' => [], 'summary' => ['total_remittance' => 25.5]];
        $result = (new HttpStatutoryRemittanceProvider())->submit($package);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('PAY-1', $result['reference']);
        $this->assertSame(64, strlen($result['integrity_hash']));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer payroll-secret')
            && $request->hasHeader('X-ERP-Package-SHA256', $result['integrity_hash'])
            && $request->hasHeader('Idempotency-Key', 'ERP-STATUTORY-'.$result['integrity_hash'])
            && $request['company_id'] === 9);
    }

    public function test_http_provider_requires_endpoint(): void
    {
        config(['integrations.statutory_remittance_http_endpoint' => null]);
        $this->expectExceptionMessage('HTTP statutory remittance endpoint is not configured');
        (new HttpStatutoryRemittanceProvider())->submit([]);
    }
}
