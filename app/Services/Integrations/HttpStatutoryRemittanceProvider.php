<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

class HttpStatutoryRemittanceProvider implements StatutoryRemittanceProvider
{
    public function key(): string { return 'http'; }

    public function submit(array $package): array
    {
        $connection = $this->connectionConfig($package);
        $endpoint = trim((string) ($connection['endpoint'] ?? config('integrations.statutory_remittance_http_endpoint')));
        if ($endpoint === '') throw new \RuntimeException('The HTTP statutory remittance endpoint is not configured.');
        $hash = hash('sha256', json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '');
        $retries = max(0, min(5, (int) ($connection['retries'] ?? config('integrations.statutory_remittance_http_retries', 2))));
        $response = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $request = Http::timeout((int) ($connection['timeout'] ?? config('integrations.statutory_remittance_http_timeout', 30)))
                    ->acceptJson()->asJson()->withHeaders(['Idempotency-Key' => 'ERP-STATUTORY-'.$hash, 'X-ERP-Package-SHA256' => $hash]);
                $token = trim((string) ($connection['token'] ?? config('integrations.statutory_remittance_http_token')));
                if ($token !== '') $request = $request->withToken($token);
                $response = $request->post($endpoint, $package);
            } catch (ConnectionException $exception) {
                if ($attempt >= $retries) throw new \RuntimeException('HTTP statutory remittance provider connection failed: '.$exception->getMessage(), 0, $exception);
                $sleep = max(0, (int) config('integrations.statutory_remittance_http_retry_sleep', 0));
                if ($sleep > 0) usleep($sleep * 1000);
                continue;
            }
            if (!(in_array($response->status(), [408, 425, 429], true) || $response->serverError()) || $attempt >= $retries) break;
        }
        if (!$response) throw new \RuntimeException('HTTP statutory remittance provider returned no response.');
        if ($response->failed()) throw new \RuntimeException('HTTP statutory remittance provider returned '.$response->status().': '.((string) ($response->json('message') ?: $response->body())));
        $body = $response->json();
        if (!is_array($body)) throw new \RuntimeException('HTTP statutory remittance provider returned invalid JSON.');
        $status = strtolower((string) ($body['status'] ?? 'submitted'));
        if (!in_array($status, ['submitted', 'accepted', 'rejected'], true)) throw new \RuntimeException('HTTP statutory remittance provider returned an invalid status.');
        return ['status' => $status, 'reference' => $body['reference'] ?? $body['remittance_reference'] ?? ('ERP-STATUTORY-'.$hash), 'response' => $body, 'integrity_hash' => $hash];
    }

    private function connectionConfig(array $package): array
    {
        $companyId = (int) ($package['company_id'] ?? 0);
        if (!$companyId) return [];
        try {
            $setting = \App\Models\HrStatutoryRemittanceProviderSetting::where('company_id', $companyId)
                ->where('provider', 'http')->where('is_active', true)->first();
        } catch (QueryException) {
            return [];
        }
        return is_array($setting?->connection_config) ? $setting->connection_config : [];
    }
}
