<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpExchangeRateProvider implements ExchangeRateProvider
{
    public function key(): string { return 'http'; }

    public function fetch(array $context = []): array
    {
        $endpoint = trim((string) config('integrations.exchange_rate_http_endpoint'));
        if ($endpoint === '') throw new \RuntimeException('The HTTP exchange-rate endpoint is not configured.');
        $query = array_filter([
            'base' => $context['base_currency'] ?? null,
            'date' => $context['effective_date'] ?? null,
            'symbols' => !empty($context['symbols']) ? implode(',', (array) $context['symbols']) : null,
        ]);
        try {
            $request = Http::timeout((int) config('integrations.exchange_rate_http_timeout', 30))->acceptJson();
            $token = trim((string) config('integrations.exchange_rate_http_token'));
            if ($token !== '') $request = $request->withToken($token);
            $response = $request->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('HTTP exchange-rate provider connection failed: '.$exception->getMessage(), 0, $exception);
        }
        if ($response->failed()) throw new \RuntimeException('HTTP exchange-rate provider returned '.$response->status().'.');
        $body = $response->json();
        if (!is_array($body)) throw new \RuntimeException('HTTP exchange-rate provider returned invalid JSON.');
        $base = strtoupper((string) ($body['base'] ?? $body['base_currency'] ?? $context['base_currency'] ?? ''));
        $effectiveDate = (string) ($body['date'] ?? $context['effective_date'] ?? now()->toDateString());
        $reference = (string) ($body['source_reference'] ?? $body['reference'] ?? ('http:'.$effectiveDate));
        $rows = [];
        if (is_array($body['rates'] ?? null)) {
            foreach ($body['rates'] as $currency => $rate) {
                $rows[] = ['from_currency' => $base, 'to_currency' => strtoupper((string) $currency), 'rate' => $rate, 'effective_date' => $effectiveDate, 'source_reference' => $reference, 'retrieved_at' => now()->toDateTimeString()];
            }
        } else {
            foreach ((array) ($body['data'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $rows[] = ['from_currency' => strtoupper((string) ($row['from_currency'] ?? $row['from'] ?? $base)), 'to_currency' => strtoupper((string) ($row['to_currency'] ?? $row['to'] ?? '')), 'rate' => $row['rate'] ?? null, 'effective_date' => $row['effective_date'] ?? $effectiveDate, 'source_reference' => $row['source_reference'] ?? $reference, 'retrieved_at' => $row['retrieved_at'] ?? now()->toDateTimeString()];
            }
        }
        return array_values(array_filter($rows, fn (array $row): bool => $row['from_currency'] !== '' && $row['to_currency'] !== '' && is_numeric($row['rate']) && (float) $row['rate'] > 0));
    }
}
