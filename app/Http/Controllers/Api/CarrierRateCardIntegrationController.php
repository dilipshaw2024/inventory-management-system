<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarrierRateCard;
use App\Models\Delivery;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarrierRateCardIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:255'],
            'service_code' => ['nullable', 'string', 'max:80'],
            'active_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $cards = CarrierRateCard::where('company_id', $companyId)
            ->when($data['carrier'] ?? null, fn ($q, $value) => $q->where('carrier', $value))
            ->when($data['service_code'] ?? null, fn ($q, $value) => $q->where('service_code', $value))
            ->when(($data['active_only'] ?? true), fn ($q) => $q->where('is_active', true))
            ->orderBy('carrier')->orderBy('service_code')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 50));
        return response()->json(['data' => $cards->getCollection()->values(), 'meta' => ['current_page' => $cards->currentPage(), 'per_page' => $cards->perPage(), 'total' => $cards->total(), 'last_page' => $cards->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $this->validated($request);
        if (!empty($data['external_reference'])) {
            $existing = CarrierRateCard::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $card = DB::transaction(function () use ($data, $companyId): CarrierRateCard {
            $card = CarrierRateCard::create($data + ['company_id' => $companyId, 'currency_code' => strtoupper($data['currency_code'])]);
            app(AuditService::class)->record('carrier_rate_card.created', $card, null, $card->toArray());
            return $card;
        });
        return response()->json(['data' => $card, 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $card = CarrierRateCard::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validated($request, true);
        $before = $card->toArray();
        $card->update($data + (isset($data['currency_code']) ? ['currency_code' => strtoupper($data['currency_code'])] : []));
        app(AuditService::class)->record('carrier_rate_card.updated', $card, $before, $card->fresh()->toArray());
        return response()->json(['data' => $card->fresh(), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $card = CarrierRateCard::where('company_id', $request->user()?->company_id)->findOrFail($id);
        if (!$card->is_active) return response()->json(['data' => $card, 'status' => 'already_deactivated']);
        $card->update(['is_active' => false]);
        app(AuditService::class)->record('carrier_rate_card.deactivated', $card, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $card->fresh(), 'status' => 'deactivated']);
    }

    public function selectForDelivery(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'rate_card_id' => ['required', 'integer'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'origin_zone' => ['nullable', 'string', 'max:80'],
            'destination_zone' => ['nullable', 'string', 'max:80'],
            'as_of' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        $storeId = (int) ($request->user()?->store_id ?? 0);
        $deliveryQuery = Delivery::query()->where('company_id', $companyId)
            ->when($storeId > 0, fn ($query) => $query->whereHas('salesOrder', fn ($order) => $order->where('store_id', $storeId)));
        if (!empty($data['external_reference'])) {
            $existing = Delivery::where('company_id', $companyId)->where('carrier_quote_external_reference', $data['external_reference'])->first();
            if ($existing && (int) $existing->id !== $id) abort(422, 'The carrier quote external reference is already used by another delivery.');
            if ($existing && (int) $existing->id === $id) {
                return response()->json(['data' => $this->deliveryQuotePayload($existing->load('carrierRateCard')), 'status' => 'duplicate_ignored']);
            }
        }
        $asOf = $data['as_of'] ?? now()->toDateString();
        $delivery = DB::transaction(function () use ($data, $companyId, $id, $asOf, $deliveryQuery, $request): Delivery {
            $delivery = $deliveryQuery->with('packages')->lockForUpdate()->findOrFail($id);
            if ($delivery->status !== 'approved' || in_array($delivery->fulfillment_status, ['delivered', 'cancelled'], true)) {
                abort(422, 'Only an approved, not-yet-completed delivery can receive a carrier quote.');
            }
            $card = CarrierRateCard::where('company_id', $companyId)->where('is_active', true)->findOrFail((int) $data['rate_card_id']);
            if ($delivery->carrier && $delivery->carrier !== $card->carrier) abort(422, 'The selected rate card carrier does not match the delivery carrier.');
            $weight = array_key_exists('weight_kg', $data) && $data['weight_kg'] !== null ? (float) $data['weight_kg'] : $this->deliveryWeightKg($delivery);
            if ($weight === null || $weight <= 0) abort(422, 'A delivery must have a positive total or package weight before a carrier quote can be selected.');
            if (!$this->rateCardMatches($card, $weight, $data['origin_zone'] ?? null, $data['destination_zone'] ?? null, $asOf)) abort(422, 'The selected carrier rate card is not valid for this delivery weight, zone, or date.');
            $before = $delivery->only(['carrier', 'carrier_rate_card_id', 'carrier_service_code', 'carrier_quote_amount', 'carrier_quote_currency', 'carrier_quote_weight_kg', 'carrier_quote_origin_zone', 'carrier_quote_destination_zone', 'carrier_quote_at', 'carrier_quote_external_reference']);
            $delivery->update([
                'carrier' => $card->carrier,
                'carrier_rate_card_id' => $card->id,
                'carrier_service_code' => $card->service_code,
                'carrier_quote_amount' => round((float) $card->base_amount + ($weight * (float) $card->per_kg_amount), 6),
                'carrier_quote_currency' => $card->currency_code,
                'carrier_quote_weight_kg' => $weight,
                'carrier_quote_origin_zone' => $data['origin_zone'] ?? null,
                'carrier_quote_destination_zone' => $data['destination_zone'] ?? null,
                'carrier_quote_at' => now(),
                'carrier_quote_external_reference' => $data['external_reference'] ?? null,
            ]);
            app(AuditService::class)->record('delivery.carrier_quote_selected', $delivery, $before, $delivery->fresh()->only(array_keys($before)) + ['rate_card_id' => $card->id, 'selected_by' => $request->user()?->id]);
            return $delivery->fresh('carrierRateCard');
        });
        return response()->json(['data' => $this->deliveryQuotePayload($delivery), 'status' => 'selected']);
    }

    public function autoSelectForDelivery(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:255'],
            'origin_zone' => ['nullable', 'string', 'max:80'],
            'destination_zone' => ['nullable', 'string', 'max:80'],
            'as_of' => ['nullable', 'date'],
            'selection_strategy' => ['nullable', 'in:cheapest,fastest'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        $storeId = (int) ($request->user()?->store_id ?? 0);
        $deliveryQuery = Delivery::query()->where('company_id', $companyId)
            ->when($storeId > 0, fn ($query) => $query->whereHas('salesOrder', fn ($order) => $order->where('store_id', $storeId)));
        if (!empty($data['external_reference'])) {
            $existing = Delivery::where('company_id', $companyId)->where('carrier_quote_external_reference', $data['external_reference'])->first();
            if ($existing && (int) $existing->id !== $id) abort(422, 'The carrier quote external reference is already used by another delivery.');
            if ($existing && (int) $existing->id === $id) {
                return response()->json(['data' => $this->deliveryQuotePayload($existing->load('carrierRateCard')), 'status' => 'duplicate_ignored']);
            }
        }

        $asOf = $data['as_of'] ?? now()->toDateString();
        $strategy = $data['selection_strategy'] ?? 'cheapest';
        $delivery = DB::transaction(function () use ($data, $companyId, $id, $asOf, $strategy, $deliveryQuery, $request): Delivery {
            $delivery = $deliveryQuery->with('packages')->lockForUpdate()->findOrFail($id);
            if ($delivery->status !== 'approved' || in_array($delivery->fulfillment_status, ['delivered', 'cancelled'], true)) {
                abort(422, 'Only an approved, not-yet-completed delivery can receive a carrier quote.');
            }
            $weight = $this->deliveryWeightKg($delivery);
            if ($weight === null || $weight <= 0) abort(422, 'A delivery must have a positive total or package weight before a carrier quote can be selected.');
            $carrier = $data['carrier'] ?? $delivery->carrier;
            $cards = CarrierRateCard::where('company_id', $companyId)->where('is_active', true)
                ->when($carrier, fn ($q, $value) => $q->where('carrier', $value))
                ->where(fn ($q) => $q->whereNull('origin_zone')->orWhere('origin_zone', $data['origin_zone'] ?? null))
                ->where(fn ($q) => $q->whereNull('destination_zone')->orWhere('destination_zone', $data['destination_zone'] ?? null))
                ->where(fn ($q) => $q->whereNull('min_weight_kg')->orWhere('min_weight_kg', '<=', $weight))
                ->where(fn ($q) => $q->whereNull('max_weight_kg')->orWhere('max_weight_kg', '>=', $weight))
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $asOf))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $asOf));
            if ($strategy === 'fastest') {
                $cards->orderByRaw('transit_days IS NULL')->orderBy('transit_days')->orderBy('base_amount')->orderBy('per_kg_amount')->orderBy('id');
            } else {
                $cards->orderByRaw('(base_amount + (per_kg_amount * ?))', [$weight])->orderBy('transit_days')->orderBy('id');
            }
            $card = $cards->first();
            if (!$card) abort(422, 'No active carrier rate card matches this delivery weight, zone, carrier, and date.');
            $amount = round((float) $card->base_amount + ((float) $card->per_kg_amount * $weight), 6);
            $before = $delivery->only(['carrier', 'carrier_rate_card_id', 'carrier_service_code', 'carrier_quote_amount', 'carrier_quote_currency', 'carrier_quote_weight_kg', 'carrier_quote_origin_zone', 'carrier_quote_destination_zone', 'carrier_quote_at', 'carrier_quote_external_reference']);
            $delivery->update([
                'carrier' => $card->carrier,
                'carrier_rate_card_id' => $card->id,
                'carrier_service_code' => $card->service_code,
                'carrier_quote_amount' => $amount,
                'carrier_quote_currency' => $card->currency_code,
                'carrier_quote_weight_kg' => $weight,
                'carrier_quote_origin_zone' => $data['origin_zone'] ?? null,
                'carrier_quote_destination_zone' => $data['destination_zone'] ?? null,
                'carrier_quote_at' => now(),
                'carrier_quote_external_reference' => $data['external_reference'] ?? null,
            ]);
            app(AuditService::class)->record('delivery.carrier_quote_auto_selected', $delivery, $before, $delivery->fresh()->only(array_keys($before)) + ['rate_card_id' => $card->id, 'selection_strategy' => $strategy, 'selected_by' => $request->user()?->id]);
            return $delivery->fresh('carrierRateCard');
        });
        return response()->json(['data' => $this->deliveryQuotePayload($delivery), 'status' => 'auto_selected', 'selection_strategy' => $strategy]);
    }

    public function quote(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'delivery_id' => ['nullable', 'integer'],
            'carrier' => ['nullable', 'string', 'max:255'],
            'service_code' => ['nullable', 'string', 'max:80'],
            'weight_kg' => ['nullable', 'numeric', 'gte:0', 'required_without:delivery_id'],
            'origin_zone' => ['nullable', 'string', 'max:80'],
            'destination_zone' => ['nullable', 'string', 'max:80'],
            'as_of' => ['nullable', 'date'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();
        $delivery = !empty($data['delivery_id'])
            ? Delivery::with('packages')->where('company_id', $companyId)->findOrFail((int) $data['delivery_id'])
            : null;
        $carrier = $data['carrier'] ?? $delivery?->carrier;
        $weight = array_key_exists('weight_kg', $data) && $data['weight_kg'] !== null
            ? (float) $data['weight_kg']
            : $this->deliveryWeightKg($delivery);
        if ($weight === null) return response()->json(['message' => 'A delivery must have a positive total or package weight before it can be quoted.'], 422);
        $cards = CarrierRateCard::where('company_id', $companyId)->where('is_active', true)
            ->when($carrier, fn ($q, $value) => $q->where('carrier', $value))
            ->when($data['service_code'] ?? null, fn ($q, $value) => $q->where('service_code', $value))
            ->where(fn ($q) => $q->whereNull('origin_zone')->orWhere('origin_zone', $data['origin_zone'] ?? null))
            ->where(fn ($q) => $q->whereNull('destination_zone')->orWhere('destination_zone', $data['destination_zone'] ?? null))
            ->where(fn ($q) => $q->whereNull('min_weight_kg')->orWhere('min_weight_kg', '<=', $weight))
            ->where(fn ($q) => $q->whereNull('max_weight_kg')->orWhere('max_weight_kg', '>=', $weight))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $asOf))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $asOf))
            ->orderBy('base_amount')->orderBy('per_kg_amount')->orderBy('transit_days')->orderBy('id')
            ->get();
        $quotes = $cards->map(fn (CarrierRateCard $card): array => [
            'rate_card_id' => (int) $card->id,
            'carrier' => $card->carrier,
            'service_code' => $card->service_code,
            'currency_code' => $card->currency_code,
            'weight_kg' => $weight,
            'base_amount' => (float) $card->base_amount,
            'per_kg_amount' => (float) $card->per_kg_amount,
            'total_amount' => round((float) $card->base_amount + ($weight * (float) $card->per_kg_amount), 6),
            'transit_days' => $card->transit_days,
        ])->values();
        return response()->json(['data' => $quotes, 'meta' => ['as_of' => $asOf, 'weight_kg' => $weight, 'delivery_id' => $delivery?->id, 'count' => $quotes->count()]]);
    }

    private function rateCardMatches(CarrierRateCard $card, float $weight, ?string $originZone, ?string $destinationZone, string $asOf): bool
    {
        if ($card->origin_zone !== null && $card->origin_zone !== $originZone) return false;
        if ($card->destination_zone !== null && $card->destination_zone !== $destinationZone) return false;
        if ($card->min_weight_kg !== null && (float) $card->min_weight_kg > $weight) return false;
        if ($card->max_weight_kg !== null && (float) $card->max_weight_kg < $weight) return false;
        if ($card->valid_from !== null && $card->valid_from->toDateString() > $asOf) return false;
        if ($card->valid_until !== null && $card->valid_until->toDateString() < $asOf) return false;
        return true;
    }

    private function deliveryQuotePayload(Delivery $delivery): array
    {
        return [
            'delivery_id' => (int) $delivery->id,
            'carrier' => $delivery->carrier,
            'rate_card_id' => $delivery->carrier_rate_card_id ? (int) $delivery->carrier_rate_card_id : null,
            'service_code' => $delivery->carrier_service_code,
            'amount' => $delivery->carrier_quote_amount !== null ? (float) $delivery->carrier_quote_amount : null,
            'currency_code' => $delivery->carrier_quote_currency,
            'weight_kg' => $delivery->carrier_quote_weight_kg !== null ? (float) $delivery->carrier_quote_weight_kg : null,
            'origin_zone' => $delivery->carrier_quote_origin_zone,
            'destination_zone' => $delivery->carrier_quote_destination_zone,
            'quoted_at' => optional($delivery->carrier_quote_at)->toISOString(),
            'external_reference' => $delivery->carrier_quote_external_reference,
        ];
    }

    private function deliveryWeightKg(?Delivery $delivery): ?float
    {
        if (!$delivery) return null;
        $total = (float) ($delivery->total_weight ?? 0);
        $unit = strtolower((string) ($delivery->weight_unit ?? 'kg'));
        if ($total > 0) return match ($unit) {
            'g', 'gram', 'grams' => $total / 1000,
            'lb', 'lbs', 'pound', 'pounds' => $total * 0.45359237,
            default => $total,
        };
        $packageWeight = (float) $delivery->packages->sum(function ($package): float {
            $value = (float) ($package->weight ?? 0);
            return match (strtolower((string) ($package->weight_unit ?? 'kg'))) {
                'g', 'gram', 'grams' => $value / 1000,
                'lb', 'lbs', 'pound', 'pounds' => $value * 0.45359237,
                default => $value,
            };
        });
        return $packageWeight > 0 ? $packageWeight : null;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'carrier' => [$required, 'string', 'max:255'],
            'service_code' => [$required, 'string', 'max:80'],
            'origin_zone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'destination_zone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'min_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'max_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gte:min_weight_kg'],
            'base_amount' => [$required, 'numeric', 'gte:0'],
            'per_kg_amount' => [$required, 'numeric', 'gte:0'],
            'currency_code' => [$required, 'string', 'size:3'],
            'transit_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['currency_code'])) $data['currency_code'] = strtoupper($data['currency_code']);
        return $data;
    }
}
