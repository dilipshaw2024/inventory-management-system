<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryTrackingEvent;
use App\Models\DispatchManifest;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchManifestController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 422, 'A company is required for dispatch manifests.');
        return $companyId;
    }

    private function deliveriesQuery(int $companyId)
    {
        return Delivery::with(['salesOrder.customer', 'packages'])
            ->where('company_id', $companyId)
            ->when((int) auth()->user()?->store_id > 0, fn ($query) => $query->whereHas('salesOrder', fn ($order) => $order->where('store_id', (int) auth()->user()->store_id)));
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $eligibleDeliveries = $this->deliveriesQuery($companyId)
            ->where('status', 'approved')->where('fulfillment_status', 'dispatched')
            ->whereDoesntHave('dispatchManifests', fn ($query) => $query->whereIn('dispatch_manifests.status', ['planned', 'handed_off']))
            ->latest('date')->latest('id')->get();
        $manifests = DispatchManifest::with(['deliveries'])->where('company_id', $companyId)
            ->when((int) auth()->user()?->store_id > 0, fn ($query) => $query->whereHas('deliveries.salesOrder', fn ($order) => $order->where('store_id', (int) auth()->user()->store_id)))
            ->latest('manifest_date')->latest('id')->paginate(20);

        return view('backend.stock.dispatch_manifests', compact('eligibleDeliveries', 'manifests'));
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate([
            'delivery_ids' => ['required', 'array', 'min:1', 'max:100'],
            'delivery_ids.*' => ['required', 'integer', 'distinct'],
            'carrier' => ['nullable', 'string', 'max:255'],
            'tracking_reference' => ['nullable', 'string', 'max:255'],
            'manifest_date' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $manifest = DB::transaction(function () use ($data, $companyId): DispatchManifest {
                $ids = collect($data['delivery_ids'])->map(fn ($id): int => (int) $id)->values();
                $deliveries = $this->deliveriesQuery($companyId)->with('operations')->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
                if ($deliveries->count() !== $ids->count()) throw new \RuntimeException('One or more deliveries are outside the current scope.');
                foreach ($deliveries as $delivery) {
                    if ($delivery->status !== 'approved' || $delivery->fulfillment_status !== 'dispatched') throw new \RuntimeException('Only approved, dispatched deliveries can be manifested.');
                    if ($delivery->operations->firstWhere('operation_type', 'dispatch')?->status !== 'completed') throw new \RuntimeException('Every delivery must have a completed dispatch operation.');
                    if ($delivery->dispatchManifests()->whereIn('dispatch_manifests.status', ['planned', 'handed_off'])->exists()) throw new \RuntimeException('A delivery is already assigned to an active manifest.');
                }
                $manifest = DispatchManifest::create([
                    'company_id' => $companyId,
                    'manifest_no' => app(NumberingSequenceService::class)->nextOrFallback('dispatch_manifest', 'DM-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)), $companyId, auth()->user()?->branch_id),
                    'external_reference' => $data['external_reference'] ?? null,
                    'carrier' => $data['carrier'] ?? ($deliveries->pluck('carrier')->filter()->unique()->count() === 1 ? $deliveries->pluck('carrier')->filter()->first() : null),
                    'tracking_reference' => $data['tracking_reference'] ?? null,
                    'manifest_date' => $data['manifest_date'] ?? now()->toDateString(),
                    'status' => 'planned', 'created_by' => auth()->id(),
                ]);
                $manifest->deliveries()->attach($ids->all());
                app(AuditService::class)->record('dispatch_manifest.created_from_browser', $manifest, null, ['delivery_ids' => $ids->all()]);
                return $manifest;
            });
            return back()->with(['message' => 'Dispatch manifest '.$manifest->manifest_no.' created.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['delivery_ids' => $exception->getMessage()])->withInput();
        }
    }

    public function handoff(Request $request, int $id)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['handoff_at' => ['nullable', 'date'], 'event_location' => ['nullable', 'string', 'max:255']]);
        try {
            DB::transaction(function () use ($data, $companyId, $id): void {
                $manifest = DispatchManifest::with(['deliveries.operations'])->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
                if ($manifest->status === 'handed_off') return;
                if ($manifest->status !== 'planned') throw new \RuntimeException('Only planned manifests can be handed off.');
                foreach ($manifest->deliveries as $delivery) {
                    if ($delivery->status !== 'approved' || $delivery->fulfillment_status !== 'dispatched' || $delivery->operations->firstWhere('operation_type', 'dispatch')?->status !== 'completed') throw new \RuntimeException('Every manifest delivery must remain dispatched.');
                    $reference = $manifest->manifest_no.':pickup:'.$delivery->id;
                    if (!DeliveryTrackingEvent::where('company_id', $companyId)->where('external_reference', $reference)->exists()) {
                        DeliveryTrackingEvent::create(['company_id' => $companyId, 'delivery_id' => $delivery->id, 'provider' => 'generic', 'external_reference' => $reference, 'carrier_status' => 'picked_up', 'event_at' => $data['handoff_at'] ?? now(), 'event_location' => $data['event_location'] ?? null, 'description' => 'Carrier handoff from dispatch manifest '.$manifest->manifest_no, 'created_by' => auth()->id()]);
                    }
                }
                $before = $manifest->only(['status', 'handed_off_at', 'handed_off_by']);
                $manifest->update(['status' => 'handed_off', 'handed_off_at' => $data['handoff_at'] ?? now(), 'handed_off_by' => auth()->id()]);
                app(AuditService::class)->record('dispatch_manifest.handed_off_from_browser', $manifest, $before, $manifest->fresh()->only(['status', 'handed_off_at', 'handed_off_by']));
            });
            return back()->with(['message' => 'Manifest handed off to the carrier.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
    }

    public function close(int $id)
    {
        $manifest = DispatchManifest::where('company_id', $this->companyId())->lockForUpdate()->findOrFail($id);
        if ($manifest->status !== 'handed_off') return back()->with(['message' => 'Only handed-off manifests can be closed.', 'alert-type' => 'error']);
        $manifest->update(['status' => 'closed', 'closed_at' => now()]);
        app(AuditService::class)->record('dispatch_manifest.closed_from_browser', $manifest, ['status' => 'handed_off'], ['status' => 'closed']);
        return back()->with(['message' => 'Manifest closed.', 'alert-type' => 'success']);
    }

    public function cancel(Request $request, int $id)
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $manifest = DispatchManifest::where('company_id', $this->companyId())->lockForUpdate()->findOrFail($id);
        if ($manifest->status !== 'planned') return back()->with(['message' => 'Only planned manifests can be cancelled.', 'alert-type' => 'error']);
        $manifest->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_at' => now()]);
        app(AuditService::class)->record('dispatch_manifest.cancelled_from_browser', $manifest, ['status' => 'planned'], $manifest->fresh()->only(['status', 'cancellation_reason', 'cancelled_at']));
        return back()->with(['message' => 'Manifest cancelled.', 'alert-type' => 'success']);
    }
}
