<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryTrackingEvent;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\CarrierTrackingPoller;
use App\Services\CarrierSlaPolicyService;
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

    public function slaSummary(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'carrier' => ['nullable', 'string', 'max:255'], 'overdue_hours' => ['nullable', 'numeric', 'min:1', 'max:8760'],
        ]);
        $deliveries = Delivery::query()->where('company_id', $companyId)->where('status', 'approved')
            ->with(['salesOrder.store', 'trackingEvents' => fn ($events) => $events->orderBy('event_at')->orderBy('id')])
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->when($data['carrier'] ?? null, fn ($q, $carrier) => $q->where('carrier', $carrier))->get();
        $overdueHours = (float) ($data['overdue_hours'] ?? 48);
        $policy = app(CarrierSlaPolicyService::class);
        $slaHoursFor = fn ($delivery): float => $policy->hoursFor((int) $companyId, $delivery->salesOrder?->store?->branch_id, $delivery->carrier, $overdueHours);
        $overdueBefore = now()->subHours($overdueHours);
        $statusCounts = $deliveries->groupBy(fn ($delivery) => $delivery->fulfillment_status ?: 'pending')
            ->map(fn ($items, $status) => ['status' => $status, 'count' => $items->count()])->values();
        $overdue = $deliveries->filter(fn ($delivery) => in_array($delivery->fulfillment_status, ['dispatched', 'in_transit'], true)
            && $delivery->date && now()->greaterThan($policy->deadlineAt($delivery->date->toDateString(), $slaHoursFor($delivery), (int) $companyId, $delivery->salesOrder?->store?->branch_id)));
        $transitHoursFor = function ($delivery): ?float {
            $dispatch = $delivery->trackingEvents->first(fn ($event) => in_array($event->carrier_status, ['picked_up', 'in_transit', 'out_for_delivery'], true));
            $delivered = $delivery->trackingEvents->firstWhere('carrier_status', 'delivered');
            if (!$dispatch || !$delivered) return null;
            return round($dispatch->event_at->diffInMinutes($delivered->event_at) / 60, 2);
        };
        $transitHours = $deliveries->map($transitHoursFor)->filter(fn ($hours) => $hours !== null)->values();
        $carrierMetrics = $deliveries->groupBy(fn ($delivery) => $delivery->carrier ?: 'unassigned')
            ->map(function ($items, $carrier) use ($transitHoursFor, $slaHoursFor, $policy): array {
                $delivered = $items->where('fulfillment_status', 'delivered');
                $overdueCount = $items->filter(fn ($delivery) => in_array($delivery->fulfillment_status, ['dispatched', 'in_transit'], true)
                    && $delivery->date && now()->greaterThan($this->carrierSlaDeadline($policy, $delivery, $slaHoursFor($delivery))))->count();
                $transitByDelivery = $items->mapWithKeys(fn ($delivery) => [$delivery->id => $transitHoursFor($delivery)]);
                $transit = $transitByDelivery->filter(fn ($hours) => $hours !== null)->values();
                $exceptionCount = $items->filter(fn ($delivery) => $delivery->trackingEvents->contains(fn ($event) => in_array($event->carrier_status, ['exception', 'returned'], true)))->count();
                $onTimeCount = $items->filter(function ($delivery) use ($policy, $slaHoursFor): bool {
                    $dispatch = $delivery->trackingEvents->first(fn ($event) => in_array($event->carrier_status, ['picked_up', 'in_transit', 'out_for_delivery'], true));
                    $deliveredEvent = $delivery->trackingEvents->firstWhere('carrier_status', 'delivered');
                    return $dispatch && $deliveredEvent && !$policy->isBreached($dispatch->event_at->toISOString(), $deliveredEvent->event_at->toISOString(), $slaHoursFor($delivery), (int) $delivery->company_id, $delivery->salesOrder?->store?->branch_id);
                })->count();
                $effectiveSlaHours = $items->map(fn ($delivery) => $slaHoursFor($delivery))->unique()->values();
                return [
                    'carrier' => $carrier,
                    'sla_hours' => $effectiveSlaHours->count() === 1 ? $effectiveSlaHours->first() : null,
                    'sla_hours_by_branch' => $items->mapWithKeys(fn ($delivery) => [$delivery->salesOrder?->store?->branch_id ?: 'unassigned' => $slaHoursFor($delivery)])->all(),
                    'count' => $items->count(),
                    'delivered' => $delivered->count(),
                    'in_transit' => $items->whereIn('fulfillment_status', ['dispatched', 'in_transit'])->count(),
                    'overdue_in_transit' => $overdueCount,
                    'exceptions_or_returns' => $exceptionCount,
                    'delivery_rate' => $items->count() > 0 ? round(($delivered->count() / $items->count()) * 100, 2) : 0,
                    'on_time_delivered' => $onTimeCount,
                    'on_time_rate' => $transit->isEmpty() ? null : round(($onTimeCount / $transit->count()) * 100, 2),
                    'average_transit_hours' => $transit->isEmpty() ? null : round($transit->avg(), 2),
                ];
            })->values();
        return response()->json([
            'filters' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'carrier' => $data['carrier'] ?? null, 'overdue_hours' => $overdueHours],
            'summary' => ['deliveries' => $deliveries->count(), 'delivered' => $deliveries->where('fulfillment_status', 'delivered')->count(), 'overdue_in_transit' => $overdue->count(), 'delivery_events' => $deliveries->sum(fn ($delivery) => $delivery->trackingEvents->count()), 'average_transit_hours' => $transitHours->isEmpty() ? null : round($transitHours->avg(), 2)],
            'by_status' => $statusCounts, 'by_carrier' => $carrierMetrics,
            'overdue_deliveries' => $overdue->map(fn ($delivery) => ['id' => $delivery->id, 'delivery_no' => $delivery->delivery_no, 'carrier' => $delivery->carrier, 'tracking_no' => $delivery->tracking_no, 'fulfillment_status' => $delivery->fulfillment_status, 'date' => optional($delivery->date)->toDateString(), 'age_hours' => round($delivery->date->startOfDay()->diffInMinutes(now()) / 60, 2)])->values(),
        ]);
    }

    private function carrierSlaDeadline(CarrierSlaPolicyService $policy, Delivery $delivery, float $hours): \Carbon\CarbonImmutable
    {
        return $policy->deadlineAt($delivery->date->toDateString(), $hours, (int) $delivery->company_id, $delivery->salesOrder?->store?->branch_id);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'delivery_id' => ['nullable', 'integer', Rule::exists('deliveries', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'provider' => ['nullable', 'string', 'max:50'], 'payload' => ['nullable', 'array'],
            'external_reference' => ['nullable', 'string', 'max:150'],
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

    public function sync(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $delivery = Delivery::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($id);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);
        $provider = strtolower(trim($data['provider']));
        try { $adapter = $this->carrierAdapters->resolve($provider); }
        catch (\InvalidArgumentException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        if (!$adapter instanceof CarrierTrackingPoller) return response()->json(['message' => 'The selected carrier provider does not support polling.'], 422);
        $trackingNumber = $data['tracking_number'] ?? $delivery->tracking_no;
        if (!$trackingNumber) return response()->json(['message' => 'A tracking number is required for carrier synchronization.'], 422);
        try {
            $payloads = $adapter->fetch($trackingNumber, ['tracking_number' => $trackingNumber, 'delivery_id' => $delivery->id]);
            $events = [];
            $duplicates = 0;
            foreach ($payloads as $payload) {
                $normalized = $adapter->normalize((array) $payload + ['delivery_id' => $delivery->id]);
                $normalized = validator(array_merge($normalized, ['provider' => $provider, 'raw_payload' => $payload]), [
                    'delivery_id' => ['required', 'integer'], 'provider' => ['required', 'string', 'max:50'],
                    'external_reference' => ['nullable', 'string', 'max:150'], 'carrier_status' => ['required', 'in:label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned'],
                    'event_at' => ['required', 'date'], 'event_location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'raw_payload' => ['nullable', 'array'],
                ])->validate();
                if (!empty($normalized['external_reference']) && DeliveryTrackingEvent::where('company_id', $companyId)->where('provider', $provider)->where('external_reference', $normalized['external_reference'])->exists()) {
                    $duplicates++;
                    continue;
                }
                $event = DB::transaction(function () use ($normalized, $companyId, $request): DeliveryTrackingEvent {
                    $lockedDelivery = Delivery::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->with('operations')->lockForUpdate()->findOrFail($normalized['delivery_id']);
                    $event = DeliveryTrackingEvent::create([
                        'company_id' => $companyId, 'delivery_id' => $normalized['delivery_id'], 'provider' => $normalized['provider'], 'external_reference' => $normalized['external_reference'] ?? null,
                        'carrier_status' => $normalized['carrier_status'], 'event_at' => $normalized['event_at'], 'event_location' => $normalized['event_location'] ?? null,
                        'description' => $normalized['description'] ?? null, 'raw_payload' => $normalized['raw_payload'] ?? null, 'created_by' => $request->user()?->id,
                    ]);
                    $this->synchronizeDelivery($lockedDelivery, $event);
                    app(AuditService::class)->record('delivery_tracking_event.created', $event, null, $event->toArray() + ['api' => true, 'polled' => true]);
                    return $event;
                });
                $events[] = $event->load('delivery');
            }
            return response()->json(['data' => $events, 'tracking_number' => $trackingNumber, 'summary' => ['received' => count($payloads), 'recorded' => count($events), 'duplicates' => $duplicates], 'status' => 'synchronized']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function settleCarrierCharge(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'invoice_reference' => ['nullable', 'string', 'max:150'],
            'settlement_reference' => ['required', 'string', 'max:150'],
        ]);

        return DB::transaction(function () use ($request, $companyId, $data, $id): JsonResponse {
            $delivery = Delivery::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($delivery->status !== 'approved') {
                return response()->json(['message' => 'Only approved deliveries can be settled with a carrier charge.'], 422);
            }

            $reference = trim($data['settlement_reference']);
            if ($delivery->carrier_settlement_status === 'settled') {
                if ($delivery->carrier_settlement_reference === $reference && abs((float) $delivery->carrier_charge_amount - (float) $data['amount']) < 0.000001) {
                    return response()->json(['data' => $delivery->fresh()->load('carrierSettlementJournal'), 'status' => 'settled', 'idempotent' => true]);
                }
                return response()->json(['message' => 'This delivery has already been settled with a different carrier reference or amount.'], 422);
            }

            if (Delivery::where('company_id', $companyId)->where('carrier_settlement_reference', $reference)->where('id', '!=', $delivery->id)->exists()) {
                return response()->json(['message' => 'The carrier settlement reference is already used by another delivery.'], 422);
            }

            $currency = strtoupper($data['currency'] ?? (\App\Models\Company::whereKey($companyId)->value('base_currency') ?: 'USD'));
            $rate = (float) ($data['exchange_rate'] ?? 1);
            $baseAmount = round((float) $data['amount'] * $rate, 6);
            $journal = app(\App\Services\AutomaticAccountingService::class)->postCarrierSettlement($delivery, $baseAmount, $reference);
            if (!$journal) {
                return response()->json(['message' => 'Carrier settlement mappings are incomplete. Configure freight_expense and accounts_payable mappings first.', 'status' => 'missing_mapping'], 422);
            }

            $before = $delivery->only(['carrier_settlement_status', 'carrier_charge_amount', 'carrier_settlement_reference']);
            $delivery->update([
                'carrier_charge_amount' => $data['amount'], 'carrier_charge_currency' => $currency,
                'carrier_charge_exchange_rate' => $rate, 'carrier_invoice_reference' => $data['invoice_reference'] ?? null,
                'carrier_settlement_status' => 'settled', 'carrier_settlement_reference' => $reference,
                'carrier_settled_at' => now(), 'carrier_settled_by' => $request->user()?->id,
                'carrier_settlement_journal_id' => $journal->id,
            ]);
            app(AuditService::class)->record('delivery.carrier_settled', $delivery, $before, $delivery->fresh()->only([
                'carrier_settlement_status', 'carrier_charge_amount', 'carrier_charge_currency', 'carrier_charge_exchange_rate',
                'carrier_invoice_reference', 'carrier_settlement_reference', 'carrier_settlement_journal_id',
            ]));
            return response()->json(['data' => $delivery->fresh()->load('carrierSettlementJournal'), 'status' => 'settled']);
        });
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
