<?php

namespace App\Services;

use App\Models\IntegrationWebhookDelivery;
use App\Models\IntegrationWebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\QueryException;

class WebhookService
{
    public function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function dispatch(?int $companyId, string $eventType, array $payload, ?string $eventId = null): void
    {
        if (!$companyId) return;
        IntegrationWebhookSubscription::where('company_id', $companyId)->where('is_active', true)->get()->each(function (IntegrationWebhookSubscription $subscription) use ($companyId, $eventType, $payload, $eventId): void {
            if (!in_array('*', $subscription->event_types ?: [], true) && !in_array($eventType, $subscription->event_types ?: [], true)) return;
            $attributes = ['company_id' => $companyId, 'subscription_id' => $subscription->id, 'event_type' => $eventType, 'event_id' => $eventId, 'payload' => ['event' => $eventType, 'event_id' => $eventId, 'occurred_at' => now()->toISOString(), 'data' => $payload]];
            if ($eventId === null) {
                IntegrationWebhookDelivery::create($attributes);
                return;
            }
            try {
                IntegrationWebhookDelivery::firstOrCreate(['subscription_id' => $subscription->id, 'event_id' => $eventId], $attributes);
            } catch (QueryException $exception) {
                // Another worker may have won the unique-key race. The event
                // is already durably represented, so this dispatch is safe.
                if (!IntegrationWebhookDelivery::where('subscription_id', $subscription->id)->where('event_id', $eventId)->exists()) throw $exception;
            }
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
            $response = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json', 'X-ERP-Event' => $delivery->event_type, 'X-ERP-Event-Id' => (string) ($delivery->event_id ?: $delivery->id), 'X-ERP-Delivery-Id' => (string) $delivery->id, 'X-ERP-Signature' => $this->signature($body, (string) $delivery->subscription->secret)])->withBody($body, 'application/json')->post($delivery->subscription->endpoint_url);
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
        if ((int) $delivery->attempts >= 5) {
            $delivery->status = 'dead_letter';
            $delivery->dead_lettered_at = now();
        } else {
            $delivery->status = 'failed';
            $delivery->next_attempt_at = now()->addMinutes(2 ** min(4, (int) $delivery->attempts));
        }
        $delivery->save();
        return false;
    }
}
