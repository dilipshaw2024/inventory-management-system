<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryTrackingEvent;
use App\Models\DispatchManifest;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchManifestIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:planned,handed_off,closed,cancelled'],
            'carrier' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for dispatch manifests.');

        $manifests = DispatchManifest::with(['deliveries.salesOrder.customer', 'deliveries.packages'])
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['carrier'] ?? null, fn ($query, $carrier) => $query->where('carrier', $carrier))
            ->when($data['date'] ?? null, fn ($query, $date) => $query->whereDate('manifest_date', $date))
            ->latest('manifest_date')->latest('id')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => $manifests->getCollection()->map(fn (DispatchManifest $manifest): array => $this->payload($manifest))->values(),
            'meta' => [
                'current_page' => $manifests->currentPage(), 'per_page' => $manifests->perPage(),
                'total' => $manifests->total(), 'last_page' => $manifests->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_ids' => ['required', 'array', 'min:1', 'max:100'],
            'delivery_ids.*' => ['required', 'integer', 'distinct'],
            'carrier' => ['nullable', 'string', 'max:255'],
            'tracking_reference' => ['nullable', 'string', 'max:255'],
            'manifest_date' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for dispatch manifests.');

        if (!empty($data['external_reference'])) {
            $existing = DispatchManifest::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $this->payload($existing->load(['deliveries.salesOrder.customer', 'deliveries.packages'])), 'status' => 'duplicate_ignored']);
        }

        try {
            $manifest = DB::transaction(function () use ($data, $companyId, $request): DispatchManifest {
                $deliveryIds = collect($data['delivery_ids'])->map(fn ($id): int => (int) $id)->values();
                $deliveries = Delivery::with('operations')->where('company_id', $companyId)->when((int) $request->user()?->store_id > 0, fn ($query) => $query->whereHas('salesOrder', fn ($order) => $order->where('store_id', (int) $request->user()->store_id)))->whereIn('id', $deliveryIds)->lockForUpdate()->get()->keyBy('id');
                if ($deliveries->count() !== $deliveryIds->count()) throw new \RuntimeException('One or more deliveries are outside the current company.');
                foreach ($deliveries as $delivery) {
                    if ($delivery->status !== 'approved' || $delivery->fulfillment_status !== 'dispatched') throw new \RuntimeException('Only approved, dispatched deliveries can be added to a manifest.');
                    if ($delivery->operations->firstWhere('operation_type', 'dispatch')?->status !== 'completed') throw new \RuntimeException('Every delivery must have a completed dispatch operation.');
                    if ($delivery->dispatchManifests()->whereIn('dispatch_manifests.status', ['planned', 'handed_off'])->exists()) throw new \RuntimeException('A delivery is already assigned to an active dispatch manifest.');
                }

                $fallback = 'DM-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
                $manifest = DispatchManifest::create([
                    'company_id' => $companyId,
                    'manifest_no' => app(NumberingSequenceService::class)->nextOrFallback('dispatch_manifest', $fallback, $companyId, $request->user()?->branch_id),
                    'external_reference' => $data['external_reference'] ?? null,
                    'carrier' => $data['carrier'] ?? ($deliveries->pluck('carrier')->filter()->unique()->count() === 1 ? $deliveries->pluck('carrier')->filter()->first() : null),
                    'tracking_reference' => $data['tracking_reference'] ?? null,
                    'manifest_date' => $data['manifest_date'] ?? now()->toDateString(),
                    'status' => 'planned',
                    'created_by' => $request->user()?->id,
                ]);
                $manifest->deliveries()->attach($deliveryIds->all());
                app(AuditService::class)->record('dispatch_manifest.created', $manifest, null, ['delivery_ids' => $deliveryIds->all()]);
                return $manifest;
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($manifest->load(['deliveries.salesOrder.customer', 'deliveries.packages'])), 'status' => 'planned'], 201);
    }

    public function handoff(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'handoff_at' => ['nullable', 'date'],
            'event_location' => ['nullable', 'string', 'max:255'],
        ]);
        $companyId = $request->user()?->company_id;

        $wasAlreadyHandedOff = false;
        try {
            $manifest = DB::transaction(function () use ($data, $companyId, $request, $id, &$wasAlreadyHandedOff): DispatchManifest {
                $manifest = DispatchManifest::with(['deliveries.operations'])->where('company_id', $companyId)->when((int) $request->user()?->store_id > 0, fn ($query) => $query->whereHas('deliveries.salesOrder', fn ($order) => $order->where('store_id', (int) $request->user()->store_id)))->lockForUpdate()->findOrFail($id);
                if ($manifest->status === 'handed_off') {
                    $wasAlreadyHandedOff = true;
                    return $manifest;
                }
                if ($manifest->status !== 'planned') throw new \RuntimeException('Only planned manifests can be handed off.');
                $eventAt = $data['handoff_at'] ?? now();
                foreach ($manifest->deliveries as $delivery) {
                    if ($delivery->status !== 'approved' || $delivery->fulfillment_status !== 'dispatched' || $delivery->operations->firstWhere('operation_type', 'dispatch')?->status !== 'completed') {
                        throw new \RuntimeException('Every manifest delivery must remain dispatched before handoff.');
                    }
                    $externalReference = $manifest->manifest_no.':pickup:'.$delivery->id;
                    if (!DeliveryTrackingEvent::where('company_id', $companyId)->where('external_reference', $externalReference)->exists()) {
                        DeliveryTrackingEvent::create([
                            'company_id' => $companyId, 'delivery_id' => $delivery->id,
                            'external_reference' => $externalReference, 'carrier_status' => 'picked_up',
                            'event_at' => $eventAt, 'event_location' => $data['event_location'] ?? null,
                            'description' => 'Carrier handoff from dispatch manifest '.$manifest->manifest_no,
                            'created_by' => $request->user()?->id,
                        ]);
                    }
                }
                $before = $manifest->only(['status', 'handed_off_at', 'handed_off_by']);
                $manifest->update(['status' => 'handed_off', 'handed_off_at' => $eventAt, 'handed_off_by' => $request->user()?->id]);
                app(AuditService::class)->record('dispatch_manifest.handed_off', $manifest, $before, $manifest->fresh()->only(['status', 'handed_off_at', 'handed_off_by']));
                return $manifest;
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($manifest->load(['deliveries.salesOrder.customer', 'deliveries.packages'])), 'status' => $wasAlreadyHandedOff ? 'already_handed_off' : 'handed_off']);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $manifest = DispatchManifest::where('company_id', $companyId)->when((int) $request->user()?->store_id > 0, fn ($query) => $query->whereHas('deliveries.salesOrder', fn ($order) => $order->where('store_id', (int) $request->user()->store_id)))->lockForUpdate()->findOrFail($id);
        if ($manifest->status === 'closed') return response()->json(['data' => $this->payload($manifest->load('deliveries')), 'status' => 'already_closed']);
        if ($manifest->status !== 'handed_off') return response()->json(['message' => 'Only handed-off manifests can be closed.'], 422);
        $manifest->update(['status' => 'closed', 'closed_at' => now()]);
        app(AuditService::class)->record('dispatch_manifest.closed', $manifest, ['status' => 'handed_off'], ['status' => 'closed']);
        return response()->json(['data' => $this->payload($manifest->load('deliveries')), 'status' => 'closed']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $companyId = $request->user()?->company_id;
        $manifest = DispatchManifest::where('company_id', $companyId)->when((int) $request->user()?->store_id > 0, fn ($query) => $query->whereHas('deliveries.salesOrder', fn ($order) => $order->where('store_id', (int) $request->user()->store_id)))->lockForUpdate()->findOrFail($id);
        if ($manifest->status === 'cancelled') return response()->json(['data' => $this->payload($manifest->load('deliveries')), 'status' => 'already_cancelled']);
        if ($manifest->status !== 'planned') return response()->json(['message' => 'Only planned manifests can be cancelled.'], 422);
        $before = $manifest->only(['status', 'cancellation_reason', 'cancelled_at']);
        $manifest->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_at' => now()]);
        app(AuditService::class)->record('dispatch_manifest.cancelled', $manifest, $before, $manifest->fresh()->only(['status', 'cancellation_reason', 'cancelled_at']));
        return response()->json(['data' => $this->payload($manifest->load('deliveries')), 'status' => 'cancelled']);
    }

    private function payload(DispatchManifest $manifest): array
    {
        $deliveries = $manifest->deliveries ?? collect();
        return [
            'id' => (int) $manifest->id, 'manifest_no' => $manifest->manifest_no,
            'external_reference' => $manifest->external_reference, 'carrier' => $manifest->carrier,
            'tracking_reference' => $manifest->tracking_reference,
            'manifest_date' => optional($manifest->manifest_date)->toDateString(),
            'status' => $manifest->status, 'delivery_count' => $deliveries->count(),
            'delivery_ids' => $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            'deliveries' => $deliveries->map(fn (Delivery $delivery): array => [
                'id' => (int) $delivery->id, 'delivery_no' => $delivery->delivery_no,
                'fulfillment_status' => $delivery->fulfillment_status,
                'carrier' => $delivery->carrier, 'tracking_no' => $delivery->tracking_no,
                'service_code' => $delivery->carrier_service_code,
                'quote_amount' => $delivery->carrier_quote_amount !== null ? (float) $delivery->carrier_quote_amount : null,
                'quote_currency' => $delivery->carrier_quote_currency,
                'quote_weight_kg' => $delivery->carrier_quote_weight_kg !== null ? (float) $delivery->carrier_quote_weight_kg : null,
                'package_count' => $delivery->packages?->count() ?? 0,
                'customer' => $delivery->salesOrder?->customer?->name,
            ])->values(),
            'handed_off_at' => optional($manifest->handed_off_at)->toISOString(),
            'closed_at' => optional($manifest->closed_at)->toISOString(),
            'cancelled_at' => optional($manifest->cancelled_at)->toISOString(),
            'cancellation_reason' => $manifest->cancellation_reason,
        ];
    }
}
