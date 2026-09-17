<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

class AssetValuationService
{
    /**
     * Calculate a transparent straight-line valuation snapshot without writing accounting data.
     * Stored accumulated depreciation is treated as the opening balance.
     */
    public function snapshot(object $asset, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? CarbonImmutable::instance($asOf)->startOfDay() : CarbonImmutable::now()->startOfDay();
        $cost = max(0.0, (float) ($asset->acquisition_cost ?? 0));
        $salvage = min($cost, max(0.0, (float) ($asset->salvage_value ?? 0)));
        $opening = min(max(0.0, (float) ($asset->accumulated_depreciation ?? 0)), $cost - $salvage);
        $monthsElapsed = 0;

        if ($asset->in_service_date && $asset->in_service_date->lessThanOrEqualTo($asOf)) {
            $monthsElapsed = $asset->in_service_date->diffInMonths($asOf);
        }

        $life = (int) ($asset->useful_life_months ?? 0);
        $method = $asset->depreciation_method ?? 'straight_line';
        $monthly = ($life > 0) ? (($cost - $salvage) / $life) : 0.0;
        $calculated = $method === 'units_of_production'
            ? $this->unitsDepreciation($cost, $salvage, $asset->depreciation_units_total ?? null, $asset->depreciation_units_used ?? 0)
            : $this->depreciationToDate($cost, $salvage, $life, $monthsElapsed, $method);
        $accumulated = min($cost - $salvage, max($opening, $calculated));
        $bookValue = max($salvage, $cost - $accumulated);

        return [
            'asset_id' => $asset->id ?? null,
            'as_of' => $asOf->toDateString(),
            'method' => $method,
            'acquisition_cost' => round($cost, 6),
            'salvage_value' => round($salvage, 6),
            'useful_life_months' => $life ?: null,
            'months_elapsed' => $monthsElapsed,
            'monthly_depreciation' => round($method === 'declining_balance' && $life > 0 ? $cost * (2 / $life) : $monthly, 6),
            'depreciation_units_total' => $asset->depreciation_units_total ?? null,
            'depreciation_units_used' => (float) ($asset->depreciation_units_used ?? 0),
            'depreciation_to_date' => round($accumulated, 6),
            'book_value' => round($bookValue, 6),
            'fully_depreciated' => (($life > 0 && $method !== 'units_of_production') || ($method === 'units_of_production' && (float) ($asset->depreciation_units_total ?? 0) > 0)) && $accumulated >= ($cost - $salvage),
        ];
    }

    private function depreciationToDate(float $cost, float $salvage, int $life, int $months, string $method): float
    {
        if ($life <= 0 || $months <= 0) return 0.0;
        if ($method !== 'declining_balance') return min($cost - $salvage, (($cost - $salvage) / $life) * min($months, $life));

        $book = $cost;
        $depreciation = 0.0;
        $rate = 2 / $life;
        for ($month = 0; $month < min($months, $life); $month++) {
            $charge = min(max(0.0, $book - $salvage), $book * $rate);
            $depreciation += $charge;
            $book -= $charge;
        }
        return min($cost - $salvage, $depreciation);
    }

    private function unitsDepreciation(float $cost, float $salvage, $total, $used): float
    {
        $total = (float) $total;
        if ($total <= 0) return 0.0;
        return min($cost - $salvage, max(0.0, $cost - $salvage) * min(1.0, max(0.0, (float) $used) / $total));
    }
}
