<?php

namespace Tests\Support;

use App\Services\Integrations\ExchangeRateProvider;

class FakeExchangeRateProvider implements ExchangeRateProvider
{
    public function key(): string { return 'test-fx'; }

    public function fetch(array $context = []): array
    {
        return [['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate' => 0.92, 'effective_date' => '2026-10-05', 'source_reference' => 'TEST-FX-1']];
    }
}
