<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class GenericCarrierTrackingAdapter implements CarrierTrackingAdapter
{
    public function key(): string
    {
        return 'generic';
    }

    public function normalize(array $payload): array
    {
        $deliveryId = $payload['delivery_id'] ?? $payload['deliveryId'] ?? null;
        $status = $payload['carrier_status'] ?? $payload['status'] ?? null;
        $eventAt = $payload['event_at'] ?? $payload['occurred_at'] ?? $payload['timestamp'] ?? null;

        if (!$deliveryId || !$status || !$eventAt) {
            throw new InvalidArgumentException('Carrier payload must include delivery_id, status, and event_at.');
        }

        return [
            'delivery_id' => (int) $deliveryId,
            'external_reference' => $payload['external_reference'] ?? $payload['event_id'] ?? $payload['id'] ?? null,
            'carrier_status' => (string) $status,
            'event_at' => (string) $eventAt,
            'event_location' => $payload['event_location'] ?? $payload['location'] ?? null,
            'description' => $payload['description'] ?? $payload['message'] ?? null,
        ];
    }
}
