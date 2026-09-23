<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpBankStatementAdapter implements BankStatementAdapter, BankStatementPoller
{
    public function key(): string { return 'http'; }

    public function normalize(array $payload): array
    {
        return (new GenericBankStatementAdapter())->normalize($payload);
    }

    public function fetch(int $bankAccountId, array $context = []): array
    {
        $connection = is_array($context['connection_config'] ?? null) ? $context['connection_config'] : [];
        unset($context['connection_config']);
        $endpoint = (string) ($connection['endpoint'] ?? config('integrations.bank_statement_http_endpoint'));
        if ($endpoint === '') throw new RuntimeException('HTTP bank statement endpoint is not configured.');
        $url = str_replace('{bank_account_id}', rawurlencode((string) $bankAccountId), $endpoint);
        $request = Http::timeout((int) ($connection['timeout'] ?? config('integrations.bank_statement_http_timeout', 30)))
            ->retry((int) ($connection['retries'] ?? config('integrations.bank_statement_http_retries', 2)), (int) ($connection['retry_sleep'] ?? config('integrations.bank_statement_http_retry_sleep', 0)));
        $token = $connection['token'] ?? config('integrations.bank_statement_http_token');
        if ($token) $request = $request->withToken($token);
        $response = $request->get($url, $context);
        if (!$response->successful()) throw new RuntimeException('HTTP bank statement provider returned status '.$response->status().'.');
        $payload = $response->json();
        if (!is_array($payload)) throw new RuntimeException('HTTP bank statement provider returned invalid JSON.');
        $lines = $payload['lines'] ?? $payload['data'] ?? $payload;
        if (!is_array($lines)) throw new RuntimeException('HTTP bank statement provider returned no statement lines.');
        return array_is_list($lines) ? $lines : [$lines];
    }
}
