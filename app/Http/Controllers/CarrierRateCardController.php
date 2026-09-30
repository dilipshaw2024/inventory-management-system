<?php

namespace App\Http\Controllers;

use App\Models\CarrierRateCard;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CarrierRateCardController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 422, 'A company is required for carrier rate cards.');
        return $companyId;
    }

    public function index()
    {
        $cards = CarrierRateCard::where('company_id', $this->companyId())
            ->orderBy('carrier')->orderBy('service_code')->orderBy('id')->paginate(25);
        return view('backend.stock.carrier_rate_cards', compact('cards'));
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();
        $data = $this->validated($request, $companyId);
        $card = CarrierRateCard::create($data + ['company_id' => $companyId, 'currency_code' => strtoupper($data['currency_code'])]);
        app(AuditService::class)->record('carrier_rate_card.created_from_browser', $card, null, $card->toArray());
        return back()->with(['message' => 'Carrier rate card created.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $companyId = $this->companyId();
        $card = CarrierRateCard::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validated($request, $companyId, $card->id, true);
        $before = $card->toArray();
        $card->update($data + ['currency_code' => strtoupper($data['currency_code'])]);
        app(AuditService::class)->record('carrier_rate_card.updated_from_browser', $card, $before, $card->fresh()->toArray());
        return back()->with(['message' => 'Carrier rate card updated.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $card = CarrierRateCard::where('company_id', $this->companyId())->findOrFail($id);
        if (!$card->is_active) return back()->with(['message' => 'Carrier rate card is already inactive.', 'alert-type' => 'info']);
        $card->update(['is_active' => false]);
        app(AuditService::class)->record('carrier_rate_card.deactivated_from_browser', $card, ['is_active' => true], ['is_active' => false]);
        return back()->with(['message' => 'Carrier rate card deactivated.', 'alert-type' => 'success']);
    }

    private function validated(Request $request, int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('carrier_rate_cards', 'external_reference')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId))],
            'carrier' => [$required, 'string', 'max:255'], 'service_code' => [$required, 'string', 'max:80'],
            'origin_zone' => ['sometimes', 'nullable', 'string', 'max:80'], 'destination_zone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'min_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'], 'max_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gte:min_weight_kg'],
            'base_amount' => [$required, 'numeric', 'gte:0'], 'per_kg_amount' => [$required, 'numeric', 'gte:0'],
            'currency_code' => [$required, 'string', 'size:3'], 'transit_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'valid_from' => ['sometimes', 'nullable', 'date'], 'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
