<?php

namespace Tests\Unit;

use App\Services\Integrations\HttpBankStatementAdapter;
use App\Services\Integrations\BankStatementAdapterRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpBankStatementAdapterTest extends TestCase
{
    public function test_http_bank_statement_adapter_fetches_provider_lines(): void
    {
        config([
            'integrations.bank_statement_http_endpoint' => 'https://bank.example.test/accounts/{bank_account_id}/statement',
            'integrations.bank_statement_http_token' => 'secret-token',
        ]);
        Http::fake(['https://bank.example.test/*' => Http::response(['lines' => [
            ['bank_account_id' => 42, 'transaction_date' => '2026-09-20', 'amount' => 125.50, 'transaction_id' => 'BANK-1'],
        ]])]);

        $lines = app(HttpBankStatementAdapter::class)->fetch(42, ['from' => '2026-09-01', 'to' => '2026-09-20']);

        $this->assertSame('BANK-1', $lines[0]['transaction_id']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://bank.example.test/accounts/42/statement?from=2026-09-01&to=2026-09-20'
            && $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_http_provider_is_discoverable_as_polling_and_not_ready_without_endpoint(): void
    {
        config(['integrations.bank_statement_http_endpoint' => null]);
        $registry = app(BankStatementAdapterRegistry::class);
        $adapter = $registry->resolve('http');

        $this->assertInstanceOf(\App\Services\Integrations\BankStatementPoller::class, $adapter);
        $this->assertSame('http', $adapter->key());
    }
}
