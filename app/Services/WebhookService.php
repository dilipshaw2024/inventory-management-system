<?php

namespace App\Services;

use App\Models\IntegrationWebhookDelivery;
use App\Models\IntegrationWebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class WebhookService
{
    public function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function dispatch(?int $companyId, string $eventType, array $payload): void
    {
        if (!$companyId) return;
        IntegrationWebhookSubscription::where('company_id', $companyId)->where('is_active', true)->get()->each(function (IntegrationWebhookSubscription $subscription) use ($companyId, $eventType, $payload): void {
            if (!in_array('*', $subscription->event_types ?: [], true) && !in_array($eventType, $subscription->event_types ?: [], true)) return;
            IntegrationWebhookDelivery::create(['company_id' => $companyId, 'subscription_id' => $subscription->id, 'event_type' => $eventType, 'payload' => ['event' => $eventType, 'occurred_at' => now()->toISOString(), 'data' => $payload]]);
        });
    }

    public function deliver(IntegrationWebhookDelivery $delivery): bool
    {
        return (bool) (Cache::lock('erp:webhook:delivery:'.$delivery->getKey(), 120)->get(function () use ($delivery): bool {
            return $this->deliverUnlocked($delivery);
        }) ?? false);
    }

    private function deliverUnlocked(IntegrationWebhookDelivery $delivery): bool
    {
        $delivery->loadMissing('subscription');
        $delivery->attempts = (int) $delivery->attempts + 1;
        $delivery->next_attempt_at = null;
        $delivery->save();
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        try {
            $response = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json', 'X-ERP-Event' => $delivery->event_type, 'X-ERP-Delivery-Id' => (string) $delivery->id, 'X-ERP-Signature' => $this->signature($body, (string) $delivery->subscription->secret)])->withBody($body, 'application/json')->post($delivery->subscription->endpoint_url);
            $delivery->response_code = $response->status();
            if ($response->successful()) {
                $delivery->status = 'sent'; $delivery->delivered_at = now(); $delivery->last_error = null;
                $delivery->subscription->update(['last_delivered_at' => now()]);
                $delivery->save();
                return true;
            }
            $delivery->last_error = 'Webhook endpoint returned HTTP '.$response->status();
        } catch (\Throwable $exception) {
            $delivery->last_error = $exception->getMessage();
        }
        $delivery->status = 'failed';
        if ((int) $delivery->attempts < 5) $delivery->next_attempt_at = now()->addMinutes(2 ** min(4, (int) $delivery->attempts));
        $delivery->save();
        return false;
    }
}
