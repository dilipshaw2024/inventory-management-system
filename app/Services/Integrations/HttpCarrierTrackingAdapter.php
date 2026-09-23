<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpCarrierTrackingAdapter implements CarrierTrackingAdapter, CarrierTrackingPoller
{
    public function key(): string { return 'http'; }

    public function normalize(array $payload): array
    {
        return (new GenericCarrierTrackingAdapter())->normalize($payload);
    }

    public function fetch(string $trackingNumber, array $context = []): array
    {
        $connection = $this->connectionConfig($context);
        $endpoint = (string) ($connection['endpoint'] ?? config('integrations.carrier_tracking_http_endpoint'));
        if ($endpoint === '') throw new RuntimeException('HTTP carrier tracking endpoint is not configured.');
        $url = str_replace('{tracking_number}', rawurlencode($trackingNumber), $endpoint);
        $request = Http::timeout((int) ($connection['timeout'] ?? config('integrations.carrier_tracking_http_timeout', 30)))
            ->retry((int) ($connection['retries'] ?? config('integrations.carrier_tracking_http_retries', 2)), (int) ($connection['retry_sleep'] ?? config('integrations.carrier_tracking_http_retry_sleep', 0)));
        $token = $connection['token'] ?? config('integrations.carrier_tracking_http_token');
        if ($token) $request = $request->withToken($token);
        $response = $request->get($url, $context);
        if (!$response->successful()) throw new RuntimeException('HTTP carrier tracking provider returned status '.$response->status().'.');
        $payload = $response->json();
        if (!is_array($payload)) throw new RuntimeException('HTTP carrier tracking provider returned an invalid JSON payload.');
        $events = $payload['events'] ?? $payload['data'] ?? $payload;
        if (!is_array($events)) throw new RuntimeException('HTTP carrier tracking provider returned no tracking events.');
        return array_is_list($events) ? $events : [$events];
    }

    private function connectionConfig(array $context): array
    {
        $companyId = $context['company_id'] ?? null;
        if (!$companyId) return [];
        $setting = \App\Models\CarrierTrackingProviderSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('provider', 'http')->where('is_active', true)->first();
        return is_array($setting?->connection_config) ? $setting->connection_config : [];
    }
}
