<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryTrackingEvent;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\CarrierTrackingPoller;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CarrierTrackingSyncService
{
    public function __construct(private CarrierTrackingAdapterRegistry $carrierAdapters) {}

    /**
     * Poll one company-owned delivery and persist normalized, idempotent events.
     *
     * @return array{status:string, provider:string, tracking_number:string, received:int, recorded:int, duplicates:int}
     */
    public function sync(Delivery $delivery, string $provider, ?string $trackingNumber = null, ?int $userId = null): array
    {
        $provider = strtolower(trim($provider));
        $adapter = $this->carrierAdapters->resolve($provider);
        if (!$adapter instanceof CarrierTrackingPoller) {
            throw new RuntimeException('The selected carrier provider does not support polling.');
        }

        $trackingNumber = trim((string) ($trackingNumber ?: $delivery->tracking_no));
        if ($trackingNumber === '') {
            throw new RuntimeException('A tracking number is required for carrier synchronization.');
        }

        $companyId = (int) $delivery->company_id;
        $payloads = $adapter->fetch($trackingNumber, [
            'tracking_number' => $trackingNumber,
            'delivery_id' => $delivery->id,
            'company_id' => $companyId,
        ]);
        $recorded = 0;
        $duplicates = 0;

        foreach ($payloads as $payload) {
            $normalized = $adapter->normalize((array) $payload + ['delivery_id' => $delivery->id]);
            $normalized = validator(array_merge($normalized, [
                'provider' => $provider,
                'raw_payload' => $payload,
            ]), [
                'delivery_id' => ['required', 'integer'],
                'provider' => ['required', 'string', 'max:50'],
                'external_reference' => ['nullable', 'string', 'max:150'],
                'carrier_status' => ['required', 'in:label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned'],
                'event_at' => ['required', 'date'],
                'event_location' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:3000'],
                'raw_payload' => ['nullable', 'array'],
            ])->validate();

            if (!empty($normalized['external_reference'])
                && DeliveryTrackingEvent::where('company_id', $companyId)
                    ->where('provider', $provider)
                    ->where('external_reference', $normalized['external_reference'])
                    ->exists()) {
                $duplicates++;
                continue;
            }

            DB::transaction(function () use ($normalized, $companyId, $userId): void {
                $lockedDelivery = Delivery::where('company_id', $companyId)
                    ->with('operations')
                    ->lockForUpdate()
                    ->findOrFail($normalized['delivery_id']);

                $event = DeliveryTrackingEvent::create([
                    'company_id' => $companyId,
                    'delivery_id' => $normalized['delivery_id'],
                    'provider' => $normalized['provider'],
                    'external_reference' => $normalized['external_reference'] ?? null,
                    'carrier_status' => $normalized['carrier_status'],
                    'event_at' => $normalized['event_at'],
                    'event_location' => $normalized['event_location'] ?? null,
                    'description' => $normalized['description'] ?? null,
                    'raw_payload' => $normalized['raw_payload'] ?? null,
                    'created_by' => $userId,
                ]);

                $this->synchronizeDelivery($lockedDelivery, $event);
                app(AuditService::class)->record(
                    'delivery_tracking_event.created',
                    $event,
                    null,
                    $event->toArray() + ['polled' => true, 'scheduled' => $userId === null]
                );
            });
            $recorded++;
        }

        return [
            'status' => 'synchronized',
            'provider' => $provider,
            'tracking_number' => $trackingNumber,
            'received' => count($payloads),
            'recorded' => $recorded,
            'duplicates' => $duplicates,
        ];
    }

    private function synchronizeDelivery(Delivery $delivery, DeliveryTrackingEvent $event): void
    {
        if ($delivery->status !== 'approved' || $delivery->fulfillment_status === 'cancelled') return;

        $hasDispatch = $delivery->operations->contains(fn ($operation): bool => $operation->operation_type === 'dispatch');
        if (!$hasDispatch) return;

        $current = $delivery->fulfillment_status ?: 'pending';
        if ($event->carrier_status === 'delivered' && $current !== 'delivered') {
            $before = $delivery->only(['fulfillment_status', 'delivered_at']);
            $delivery->update(['fulfillment_status' => 'delivered', 'delivered_at' => $event->event_at]);
            app(\App\Services\WarehouseFulfillmentService::class)->markDelivered($delivery, $event->event_at);
            app(AuditService::class)->record('delivery.delivered_by_carrier', $delivery, $before, $delivery->fresh()->only(['fulfillment_status', 'delivered_at']) + ['tracking_event_id' => $event->id]);
            return;
        }

        if (in_array($event->carrier_status, ['picked_up', 'in_transit', 'out_for_delivery'], true)
            && $current !== 'delivered' && $current !== 'dispatched') {
            $before = $delivery->only(['fulfillment_status']);
            $delivery->update(['fulfillment_status' => 'dispatched']);
            app(AuditService::class)->record('delivery.dispatched_by_carrier', $delivery, $before, $delivery->fresh()->only(['fulfillment_status']) + ['tracking_event_id' => $event->id]);
        }
    }
}
