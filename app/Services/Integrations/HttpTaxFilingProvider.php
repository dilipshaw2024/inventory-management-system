<?php

namespace App\Services\Integrations;

use App\Models\TaxFiling;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpTaxFilingProvider
{
    public function submit(TaxFiling $filing): array
    {
        $connection = $this->connectionConfig($filing);
        $endpoint = trim((string) ($connection['endpoint'] ?? config('integrations.tax_filing_http_endpoint')));
        if ($endpoint === '') throw new \RuntimeException('The HTTP tax filing endpoint is not configured.');
        $payload = [
            'filing_id' => $filing->id,
            'filing_no' => $filing->filing_no,
            'external_reference' => $filing->external_reference,
            'return_type' => $filing->return_type,
            'period_from' => $filing->period_from?->toDateString(),
            'period_to' => $filing->period_to?->toDateString(),
            'jurisdiction' => $filing->jurisdiction,
            'snapshot_hash' => $filing->snapshot_hash,
            'snapshot' => $filing->snapshot_payload,
        ];
        $retries = max(0, min(5, (int) ($connection['retries'] ?? config('integrations.tax_filing_http_retries', 2))));
        $sleep = max(0, (int) ($connection['retry_sleep'] ?? config('integrations.tax_filing_http_retry_sleep', 0)));
        $response = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $request = Http::timeout((int) ($connection['timeout'] ?? config('integrations.tax_filing_http_timeout', 30)))
                    ->acceptJson()->asJson()->withHeaders(['Idempotency-Key' => 'ERP-TAX-FILING-'.$filing->id, 'X-ERP-Snapshot-Hash' => (string) $filing->snapshot_hash]);
                $token = trim((string) ($connection['token'] ?? config('integrations.tax_filing_http_token')));
                if ($token !== '') $request = $request->withToken($token);
                $response = $request->post($endpoint, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt >= $retries) throw new \RuntimeException('HTTP tax filing provider connection failed: '.$exception->getMessage(), 0, $exception);
                if ($sleep > 0) usleep($sleep * 1000);
                continue;
            }
            $retryable = in_array($response->status(), [408, 425, 429], true) || $response->serverError();
            if (!$retryable || $attempt >= $retries) break;
            if ($sleep > 0) usleep($sleep * 1000);
        }
        if (!$response) throw new \RuntimeException('HTTP tax filing provider returned no response.');
        if ($response->failed()) throw new \RuntimeException('HTTP tax filing provider returned '.$response->status().': '.((string) ($response->json('message') ?: $response->body())));
        $body = $response->json();
        if (!is_array($body)) throw new \RuntimeException('HTTP tax filing provider returned invalid JSON.');
        $status = strtolower((string) ($body['status'] ?? 'submitted'));
        if (!in_array($status, ['submitted', 'accepted', 'rejected'], true)) throw new \RuntimeException('HTTP tax filing provider returned an invalid status.');
        return ['status' => $status, 'filing_reference' => $body['filing_reference'] ?? $body['reference'] ?? ('ERP-TAX-FILING-'.$filing->id), 'response' => $body];
    }

    private function connectionConfig(TaxFiling $filing): array
    {
        if (!$filing->company_id) return [];
        $setting = \App\Models\TaxFilingProviderSetting::withoutGlobalScopes()->where('company_id', $filing->company_id)->where('provider', 'http')->where('is_active', true)->first();
        return is_array($setting?->connection_config) ? $setting->connection_config : [];
    }
}
