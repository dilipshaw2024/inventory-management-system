<?php

namespace App\Services;

use Illuminate\Support\Collection;

class DemandForecastService
{
    public function seasonalForecast(Collection $dailySeries, \Carbon\CarbonInterface $historyStart, \Carbon\CarbonInterface $forecastStart, int $horizonDays, float $fallbackDailyRate): float
    {
        if ($horizonDays < 1 || $dailySeries->count() < 14) return max(0.0, $fallbackDailyRate) * max(0, $horizonDays);

        $start = \Carbon\CarbonImmutable::instance($historyStart)->startOfDay();
        $weekdayTotals = array_fill(0, 7, 0.0);
        $weekdayCounts = array_fill(0, 7, 0);
        foreach ($dailySeries->values() as $offset => $value) {
            $weekday = $start->addDays((int) $offset)->dayOfWeek;
            $weekdayTotals[$weekday] += max(0.0, (float) $value);
            $weekdayCounts[$weekday]++;
        }

        $forecast = 0.0;
        $future = \Carbon\CarbonImmutable::instance($forecastStart)->startOfDay();
        for ($offset = 0; $offset < $horizonDays; $offset++) {
            $weekday = $future->addDays($offset)->dayOfWeek;
            $forecast += $weekdayCounts[$weekday] > 0
                ? $weekdayTotals[$weekday] / $weekdayCounts[$weekday]
                : max(0.0, $fallbackDailyRate);
        }
        return max(0.0, $forecast);
    }

    /** @return array{low: float, high: float} */
    public function confidenceBand(Collection $dailySeries, float $dailyRate, int $horizonDays, float $forecastQuantity): array
    {
        if ($dailySeries->count() < 2 || $horizonDays < 1) return ['low' => max(0, $forecastQuantity), 'high' => max(0, $forecastQuantity)];

        $variance = (float) $dailySeries->map(fn ($value): float => ((float) $value - $dailyRate) ** 2)->sum() / ($dailySeries->count() - 1);
        $margin = 1.96 * sqrt(max(0, $variance)) * sqrt($horizonDays);
        return ['low' => max(0, $forecastQuantity - $margin), 'high' => max(0, $forecastQuantity + $margin)];
    }
}
