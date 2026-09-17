<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;

class UomConversionService
{
    public function toStock(Product $product, float $quantity, ?int $unitId = null, ?string $usage = null, ?string $asOf = null): float
    {
        if (!$unitId || $unitId === (int) $product->unit_id) return $quantity;
        $configuredUom = $product->relationLoaded('uoms')
            ? $product->uoms->first(fn ($uom): bool => (int) $uom->unit_id === $unitId && (bool) $uom->is_active && $this->usageAllowed($uom->usage, $usage))
            : null;
        $knownUom = $product->relationLoaded('uoms')
            ? $product->uoms->first(fn ($uom): bool => (int) $uom->unit_id === $unitId && (bool) $uom->is_active)
            : null;
        if ($knownUom && !$this->usageAllowed($knownUom->usage, $usage)) {
            throw new \InvalidArgumentException('The selected UOM is not enabled for '.$usage.' transactions.');
        }
        $enteredUnit = $knownUom?->relationLoaded('unit')
            ? $knownUom->unit
            : Unit::query()->find($unitId);
        $stockDimension = strtolower((string) ($product->unit?->dimension ?? ''));
        $enteredDimension = strtolower((string) ($enteredUnit?->dimension ?? ''));
        if ($stockDimension !== '' && $enteredDimension !== '' && $stockDimension !== $enteredDimension) {
            throw new \InvalidArgumentException('The selected UOM dimension does not match the product stock UOM.');
        }
        $conversion = $configuredUom?->conversion_to_stock;
        if ($conversion === null && !$product->relationLoaded('uoms')) {
            $conversion = $product->uoms()->where('unit_id', $unitId)->where('is_active', true)->when($usage, fn ($query) => $query->where(fn ($nested) => $nested->whereIn('usage', ['both', $usage])->orWhereNull('usage')))->value('conversion_to_stock');
        }
        if ($conversion === null) {
            $stockUnit = $product->unit ?: Unit::query()->find($product->unit_id);
            $enteredDimension = strtolower((string) ($enteredUnit?->dimension ?? ''));
            $stockDimension = strtolower((string) ($stockUnit?->dimension ?? ''));
            if ($enteredDimension !== '' && $stockDimension !== '' && $enteredDimension !== $stockDimension) throw new \InvalidArgumentException('The selected UOM dimension does not match the product stock UOM.');
            $conversion = $this->genericConversionFactor($unitId, (int) $product->unit_id, $product->company_id, $asOf ?: now()->toDateString());
        }
        if ($conversion === null) throw new \InvalidArgumentException('No UOM conversion is configured for '.$product->name.'.');
        return $quantity * (float) $conversion;
    }

    private function usageAllowed(?string $configuredUsage, ?string $usage): bool
    {
        return !$usage || !$configuredUsage || $configuredUsage === 'both' || $configuredUsage === $usage;
    }

    private function genericConversionFactor(int $fromUnitId, int $toUnitId, ?int $companyId, string $asOf): ?float
    {
        $rules = UnitConversion::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where('is_active', true)->whereDate('effective_from', '<=', $asOf)->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf))->orderByRaw('company_id IS NULL')->get(['from_unit_id', 'to_unit_id', 'factor']);
        $edges = [];
        foreach ($rules as $rule) {
            $edges[(int) $rule->from_unit_id][(int) $rule->to_unit_id] ??= (float) $rule->factor;
        }
        $queue = [[$fromUnitId, 1.0]];
        $visited = [$fromUnitId => true];
        while ($queue) {
            [$current, $factor] = array_shift($queue);
            foreach ($edges[$current] ?? [] as $next => $edgeFactor) {
                if ($next === $toUnitId) return $factor * $edgeFactor;
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = [$next, $factor * $edgeFactor];
                }
            }
        }
        return null;
    }
}
