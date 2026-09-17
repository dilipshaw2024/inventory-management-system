<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryTrackingEvent;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliveryTrackingIntegrationController extends Controller
{
    public function __construct(private CarrierTrackingAdapterRegistry $carrierAdapters) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'delivery_id' => ['nullable', 'integer', Rule::exists('deliveries', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'provider' => ['nullable', 'string', 'max:50'],
            'carrier_status' => ['nullable', 'in:label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned'],
            'event_from' => ['nullable', 'date'], 'event_to' => ['nullable', 'date', 'after_or_equal:event_from'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $events = DeliveryTrackingEvent::with(['delivery', 'creator'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['delivery_id'] ?? null, fn ($query, $id) => $query->where('delivery_id', $id))
            ->when($data['provider'] ?? null, fn ($query, $provider) => $query->where('provider', strtolower(trim($provider))))
            ->when($data['carrier_status'] ?? null, fn ($query, $status) => $query->where('carrier_status', $status))
            ->when($data['event_from'] ?? null, fn ($query, $date) => $query->where('event_at', '>=', $date))
            ->when($data['event_to'] ?? null, fn ($query, $date) => $query->where('event_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($events, $request, 'sales.delivery-tracking-events', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'delivery_id' => ['nullable', 'integer', Rule::exists('deliveries', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'provider' => ['nullable', 'string', 'max:50'], 'payload' => ['nullable', 'array'],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('delivery_tracking_events', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'carrier_status' => ['nullable', 'in:label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned'],
            'event_at' => ['nullable', 'date'], 'event_location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
        ]);
        $provider = strtolower(trim($data['provider'] ?? 'generic'));
        $directPayload = collect($data)->except(['provider', 'payload'])->filter(fn ($value): bool => $value !== null)->all();
        $adapterPayload = array_merge($data['payload'] ?? [], $directPayload);
        try {
            $normalized = $this->carrierAdapters->resolve($provider)->normalize($adapterPayload);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $normalized['provider'] = $provider;
        $normalized['raw_payload'] = $data['payload'] ?? $adapterPayload;
        $normalized = validator($normalized, [
            'delivery_id' => ['required', 'integer'], 'provider' => ['required', 'string', 'max:50'],
            'external_reference' => ['nullable', 'string', 'max:150'], 'carrier_status' => ['required', 'in:label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned'],
            'event_at' => ['required', 'date'], 'event_location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'raw_payload' => ['nullable', 'array'],
        ])->validate();
        $data = array_merge($data, $normalized);
        $delivery = Delivery::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($data['delivery_id']);
        if (!empty($data['external_reference'])) {
            $existing = DeliveryTrackingEvent::where('company_id', $companyId)->where('provider', $provider)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('delivery'), 'status' => 'duplicate_ignored']);
        }
        $event = DB::transaction(function () use ($data, $companyId, $request): DeliveryTrackingEvent {
            $delivery = Delivery::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->with('operations')->lockForUpdate()->findOrFail($data['delivery_id']);
            $event = DeliveryTrackingEvent::create([
                'company_id' => $companyId, 'delivery_id' => $data['delivery_id'], 'provider' => $data['provider'], 'external_reference' => $data['external_reference'] ?? null,
                'carrier_status' => $data['carrier_status'], 'event_at' => $data['event_at'], 'event_location' => $data['event_location'] ?? null,
                'description' => $data['description'] ?? null, 'raw_payload' => $data['raw_payload'] ?? null, 'created_by' => $request->user()?->id,
            ]);
            $this->synchronizeDelivery($delivery, $event);
            app(AuditService::class)->record('delivery_tracking_event.created', $event, null, $event->toArray() + ['api' => true]);
            return $event;
        });
        return response()->json(['data' => $event->load('delivery'), 'status' => 'recorded'], 201);
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
