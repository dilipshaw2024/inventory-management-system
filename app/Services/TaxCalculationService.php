<?php

namespace App\Services;

class TaxCalculationService
{
    public function precision(?int $companyId = null): int
    {
        if ($companyId === null && !app()->bound('auth')) return 6;
        return max(0, min(6, (int) app(ErpSettingService::class)->get('tax_rounding_precision', 6, $companyId)));
    }

    public function roundAmount(float $amount, ?int $precision = null): float
    {
        return round($amount, $precision ?? $this->precision());
    }

    public function exclusive(float $netAmount, float $rate, ?int $precision = null): float
    {
        return $this->roundAmount($netAmount * ($rate / 100), $precision);
    }

    public function inclusive(float $grossAmount, float $rate, ?int $precision = null): array
    {
        $net = $rate > 0 ? $grossAmount / (1 + ($rate / 100)) : $grossAmount;
        $roundedNet = $this->roundAmount($net, $precision);
        return ['net' => $roundedNet, 'tax' => $this->roundAmount($grossAmount - $roundedNet, $precision)];
    }

    public function exclusiveComponents(float $netAmount, iterable $components, ?int $precision = null): array
    {
        $precision ??= $this->precision();
        $base = $netAmount;
        $totalTax = 0.0;
        $breakdown = [];
        $ordered = collect($components)->sortBy(fn ($component): array => [(int) ($component['sequence'] ?? $component->sequence ?? 0), (string) ($component['code'] ?? $component->code ?? '')]);
        foreach ($ordered as $component) {
            $rate = (float) ($component['rate'] ?? $component->rate ?? 0);
            $componentTax = $this->roundAmount($base * ($rate / 100), $precision);
            $breakdown[] = [
                'code' => (string) ($component['code'] ?? $component->code ?? ''),
                'name' => (string) ($component['name'] ?? $component->name ?? ''),
                'component_type' => (string) ($component['component_type'] ?? $component->component_type ?? 'other'),
                'rate' => $rate,
                'base' => $this->roundAmount($base, $precision),
                'tax' => $componentTax,
                'is_compound' => (bool) ($component['is_compound'] ?? $component->is_compound ?? false),
            ];
            $totalTax += $componentTax;
            if ($component['is_compound'] ?? $component->is_compound ?? false) $base += $componentTax;
        }
        return ['net' => $this->roundAmount($netAmount, $precision), 'tax' => $this->roundAmount($totalTax, $precision), 'components' => $breakdown];
    }

    /** Split an inclusive amount across ordered, optionally compound components. */
    public function inclusiveComponents(float $grossAmount, iterable $components, ?int $precision = null): array
    {
        $precision ??= $this->precision();
        $components = collect($components)->values();
        if ($components->isEmpty()) {
            return ['net' => $this->roundAmount($grossAmount, $precision), 'tax' => 0.0, 'components' => []];
        }

        // Solve the inverse of exclusiveComponents so non-compound and
        // compound components retain the same ordering semantics.
        $low = 0.0;
        $high = max(0.0, $grossAmount);
        for ($iteration = 0; $iteration < 80; $iteration++) {
            $mid = ($low + $high) / 2;
            $estimate = $this->exclusiveComponents($mid, $components, null);
            if ($mid + $estimate['tax'] < $grossAmount) $low = $mid;
            else $high = $mid;
        }

        $net = $this->roundAmount(($low + $high) / 2, $precision);
        $breakdown = $this->exclusiveComponents($net, $components, $precision);
        return ['net' => $net, 'tax' => $this->roundAmount($grossAmount - $net, $precision), 'components' => $breakdown['components']];
    }
}
