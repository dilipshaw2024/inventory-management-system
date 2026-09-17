<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitConversionIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from_unit_id' => ['nullable', 'integer'], 'to_unit_id' => ['nullable', 'integer'], 'as_of' => ['nullable', 'date'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = UnitConversion::with(['fromUnit', 'toUnit'])
            ->when($data['from_unit_id'] ?? null, fn ($query, $id) => $query->where('from_unit_id', $id))
            ->when($data['to_unit_id'] ?? null, fn ($query, $id) => $query->where('to_unit_id', $id))
            ->when($data['as_of'] ?? null, fn ($query, $date) => $query->whereDate('effective_from', '<=', $date)->where(fn ($scope) => $scope->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date)))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('effective_from')->orderBy('id');
        return response()->json(['data' => $query->paginate((int) ($data['per_page'] ?? 50))]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for UOM conversions.');
        $data = $request->validate($this->rules($companyId));
        $data['effective_from'] = $data['effective_from'] ?? now()->toDateString();
        $this->assertEffectiveRange($data['effective_from'], $data['effective_to'] ?? null);
        $from = $this->ownedUnit((int) $data['from_unit_id'], (int) $companyId);
        $to = $this->ownedUnit((int) $data['to_unit_id'], (int) $companyId);
        $this->assertCompatible($from, $to);
        if ($from->id === $to->id) abort(422, 'A unit cannot convert to itself.');
        $existing = UnitConversion::where('company_id', $companyId)->where('from_unit_id', $from->id)->where('to_unit_id', $to->id)->first();
        if ($existing && $existing->effective_from?->toDateString() === $data['effective_from']) return response()->json(['data' => $existing->load(['fromUnit', 'toUnit']), 'status' => 'duplicate_ignored']);
        $this->assertNoOverlap($companyId, $from->id, $to->id, $data['effective_from'], $data['effective_to'] ?? null);
        $conversion = DB::transaction(function () use ($data, $companyId): UnitConversion {
            $conversion = UnitConversion::create($data + ['company_id' => $companyId, 'is_active' => $data['is_active'] ?? true]);
            app(AuditService::class)->record('unit_conversion.created', $conversion, null, $conversion->toArray());
            return $conversion;
        });
        return response()->json(['data' => $conversion->load(['fromUnit', 'toUnit']), 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for UOM conversions.');
        $conversion = UnitConversion::where('company_id', $companyId)->with(['fromUnit', 'toUnit'])->findOrFail($id);
        $data = $request->validate(['factor' => ['sometimes', 'numeric', 'gt:0'], 'effective_from' => ['sometimes', 'date'], 'effective_to' => ['sometimes', 'nullable', 'date'], 'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:12'], 'is_active' => ['sometimes', 'boolean'], 'external_reference' => ['sometimes', 'nullable', 'string', 'max:150']]);
        $effectiveFrom = $data['effective_from'] ?? $conversion->effective_from?->toDateString();
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $conversion->effective_to?->toDateString();
        $this->assertEffectiveRange($effectiveFrom, $effectiveTo);
        $this->assertNoOverlap($companyId, (int) $conversion->from_unit_id, (int) $conversion->to_unit_id, $effectiveFrom, $effectiveTo, $conversion->id);
        $before = $conversion->only(array_keys($data));
        $conversion->update($data);
        app(AuditService::class)->record('unit_conversion.updated', $conversion, $before, $conversion->fresh()->only(array_keys($data)));
        return response()->json(['data' => $conversion->fresh()->load(['fromUnit', 'toUnit']), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for UOM conversions.');
        $conversion = UnitConversion::where('company_id', $companyId)->findOrFail($id);
        $conversion->update(['is_active' => false]);
        app(AuditService::class)->record('unit_conversion.deactivated', $conversion, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $conversion->fresh()->load(['fromUnit', 'toUnit']), 'status' => 'deactivated']);
    }

    private function rules(int $companyId): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        return ['from_unit_id' => ['required', 'integer', $owned('units')], 'to_unit_id' => ['required', 'integer', $owned('units')], 'factor' => ['required', 'numeric', 'gt:0'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date'], 'decimal_places' => ['nullable', 'integer', 'min:0', 'max:12'], 'is_active' => ['nullable', 'boolean'], 'external_reference' => ['nullable', 'string', 'max:150']];
    }

    private function ownedUnit(int $id, int $companyId): Unit
    {
        return Unit::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($id);
    }

    private function assertCompatible(Unit $from, Unit $to): void
    {
        if ($from->dimension && $to->dimension && strtolower($from->dimension) !== strtolower($to->dimension)) abort(422, 'UOM conversion units must share the same dimension.');
    }

    private function assertEffectiveRange(string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) abort(422, 'The effective end date must be on or after the effective start date.');
    }

    private function assertNoOverlap(int $companyId, int $fromUnitId, int $toUnitId, string $from, ?string $to, ?int $exceptId = null): void
    {
        $overlap = UnitConversion::where('company_id', $companyId)->where('from_unit_id', $fromUnitId)->where('to_unit_id', $toUnitId)->when($exceptId, fn ($query, $id) => $query->where('id', '<>', $id))->whereDate('effective_from', '<=', $to ?: '9999-12-31')->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->exists();
        if ($overlap) abort(422, 'The effective date range overlaps an existing conversion rule for this unit pair.');
    }
}
