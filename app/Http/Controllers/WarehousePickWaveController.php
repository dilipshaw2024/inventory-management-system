<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\PickWave;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehousePickWaveController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 422, 'A company is required for warehouse picking.');
        return $companyId;
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $warehouses = Warehouse::whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->where('is_active', true)->orderBy('name')->get();
        $warehouseId = (int) $request->input('warehouse_id') ?: (int) $warehouses->first()?->id;
        $sortBy = $request->input('sort_by', 'location');
        abort_unless(in_array($sortBy, ['location', 'product', 'delivery_date'], true), 422, 'Invalid pick-list sort order.');

        $deliveries = Delivery::with(['lines.product', 'location.warehouse', 'operations', 'salesOrder.customer'])
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', 'pending'))
            ->whereDoesntHave('operations', fn ($query) => $query->where('operation_type', 'pick')->where('status', 'completed'))
            ->when($warehouseId, fn ($query, $id) => $query->whereHas('location', fn ($location) => $location->where('warehouse_id', $id)))
            ->when($request->input('location_id'), fn ($query, $id) => $query->where('location_id', $id))
            ->when($request->input('date'), fn ($query, $date) => $query->whereDate('date', $date))
            ->whereDoesntHave('pickWaves', fn ($query) => $query->whereIn('pick_waves.status', ['planned', 'released', 'in_progress']))
            ->orderBy('date')->orderBy('id')->get()
            ->filter(fn (Delivery $delivery): bool => $delivery->lines->isNotEmpty())
            ->sortBy(function (Delivery $delivery) use ($sortBy): array {
                $product = (string) ($delivery->lines->first()?->product?->name ?? '');
                $location = (string) ($delivery->location?->code ?? '');
                $date = optional($delivery->date)->toDateString() ?? '';
                return match ($sortBy) {
                    'product' => [$product, $location, $date, $delivery->id],
                    'delivery_date' => [$date, $location, $delivery->id],
                    default => [$location ?: 'ZZZ', $product, $date, $delivery->id],
                };
            })->values();

        $waves = PickWave::with(['warehouse', 'deliveries'])
            ->where('company_id', $companyId)->latest('id')->paginate(15);
        return view('backend.stock.pick_waves', compact('warehouses', 'warehouseId', 'sortBy', 'deliveries', 'waves'));
    }

    public function createFromPickList(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'delivery_ids' => ['required', 'array', 'min:1', 'max:100'],
            'delivery_ids.*' => ['required', 'integer', 'distinct'],
            'wave_date' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $wave = DB::transaction(function () use ($data, $companyId): PickWave {
                $warehouse = Warehouse::whereKey($data['warehouse_id'])
                    ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
                    ->firstOrFail();
                $deliveryIds = collect($data['delivery_ids'])->map(fn ($id): int => (int) $id)->values();
                $deliveries = Delivery::with(['location', 'operations'])
                    ->where('company_id', $companyId)->whereIn('id', $deliveryIds)
                    ->lockForUpdate()->get()->keyBy('id');
                if ($deliveries->count() !== $deliveryIds->count()) throw new \RuntimeException('One or more deliveries are outside the current company.');
                foreach ($deliveries as $delivery) {
                    if ($delivery->status !== 'pending' || !in_array($delivery->fulfillment_status ?: 'pending', ['pending'], true)) throw new \RuntimeException('Only pending deliveries can be assigned.');
                    if ((int) $delivery->location?->warehouse_id !== (int) $warehouse->id) throw new \RuntimeException('Every delivery must belong to the selected warehouse.');
                    if ($delivery->operations->firstWhere('operation_type', 'pick')?->status === 'completed') throw new \RuntimeException('A delivery with completed picking cannot be assigned.');
                    if ($delivery->pickWaves()->whereIn('pick_waves.status', ['planned', 'released', 'in_progress'])->exists()) throw new \RuntimeException('A delivery is already assigned to an active wave.');
                }
                $wave = PickWave::create([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouse->id,
                    'wave_no' => app(NumberingSequenceService::class)->nextOrFallback('pick_wave', 'PW-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)), $companyId, $warehouse->branch_id),
                    'external_reference' => $data['external_reference'] ?? null,
                    'wave_date' => $data['wave_date'] ?? now()->toDateString(),
                    'status' => 'planned',
                    'created_by' => auth()->id(),
                ]);
                $wave->deliveries()->attach($deliveryIds->all());
                app(AuditService::class)->record('pick_wave.created_from_browser_pick_list', $wave, null, ['delivery_ids' => $deliveryIds->all(), 'warehouse_id' => $warehouse->id]);
                return $wave;
            });
            return back()->with(['message' => 'Pick wave '.$wave->wave_no.' created.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['delivery_ids' => $exception->getMessage()])->withInput();
        }
    }
}
