<?php

namespace App\Services;

use Illuminate\Support\Collection;

class DemandForecastService
{
    /**
     * Select the better deterministic forecast model using a trailing holdout.
     * The selector deliberately prefers the simpler average model unless the
     * weekly model improves mean absolute error by at least five percent.
     *
     * @return array{model: string, naive_error: float, weekly_error: float, exponential_error: float}
     */
    public function selectModel(Collection $dailySeries, \Carbon\CarbonInterface $historyStart, int $horizonDays, float $fallbackDailyRate): array
    {
        $values = $dailySeries->map(fn ($value): float => max(0.0, (float) $value))->values();
        $holdout = min(14, max(0, intdiv($values->count(), 4)));
        if ($holdout < 7 || $values->count() - $holdout < 14) return ['model' => 'naive', 'naive_error' => 0.0, 'weekly_error' => 0.0, 'exponential_error' => 0.0, 'metrics' => []];

        $train = $values->slice(0, $values->count() - $holdout)->values();
        $actual = $values->slice($train->count(), $holdout)->values();
        $naive = $train->count() ? (float) $train->avg() : max(0.0, $fallbackDailyRate);
        $naivePredictions = array_fill(0, $actual->count(), $naive);
        $naiveMetrics = $this->errorMetrics($actual, $naivePredictions);
        $naiveError = $naiveMetrics['mae'];
        $weeklyTotal = $this->seasonalForecast($train, $historyStart, \Carbon\CarbonImmutable::instance($historyStart)->addDays($train->count()), $holdout, $naive);
        $weeklyPredictions = $this->dailySeasonalPredictions($train, $historyStart, \Carbon\CarbonImmutable::instance($historyStart)->addDays($train->count()), $holdout, $naive);
        $weeklyMetrics = $this->errorMetrics($actual, $weeklyPredictions);
        $weeklyError = $weeklyMetrics['mae'];
        $exponentialLevel = $this->exponentialLevel($train, $naive);
        $exponentialMetrics = $this->errorMetrics($actual, array_fill(0, $actual->count(), $exponentialLevel));
        $exponentialError = $exponentialMetrics['mae'];
        $candidates = ['naive' => $naiveError, 'weekly' => $weeklyError, 'exponential' => $exponentialError];
        asort($candidates);
        $bestModel = array_key_first($candidates) ?: 'naive';
        if ($bestModel !== 'naive' && ($naiveError <= 0 || $candidates[$bestModel] >= ($naiveError * 0.95))) $bestModel = 'naive';
        return ['model' => $bestModel, 'naive_error' => $naiveError, 'weekly_error' => $weeklyError, 'exponential_error' => $exponentialError, 'metrics' => ['naive' => $naiveMetrics, 'weekly' => $weeklyMetrics, 'exponential' => $exponentialMetrics]];
    }

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

    public function exponentialForecast(Collection $dailySeries, int $horizonDays, float $fallbackDailyRate, float $alpha = 0.3): float
    {
        return max(0.0, $this->exponentialLevel($dailySeries, $fallbackDailyRate, $alpha)) * max(0, $horizonDays);
    }

    /** @return array<int, float> */
    private function dailySeasonalPredictions(Collection $dailySeries, \Carbon\CarbonInterface $historyStart, \Carbon\CarbonInterface $forecastStart, int $horizonDays, float $fallbackDailyRate): array
    {
        $start = \Carbon\CarbonImmutable::instance($historyStart)->startOfDay();
        $totals = array_fill(0, 7, 0.0); $counts = array_fill(0, 7, 0);
        foreach ($dailySeries->values() as $offset => $value) { $weekday = $start->addDays((int) $offset)->dayOfWeek; $totals[$weekday] += max(0.0, (float) $value); $counts[$weekday]++; }
        $future = \Carbon\CarbonImmutable::instance($forecastStart)->startOfDay(); $predictions = [];
        for ($offset = 0; $offset < $horizonDays; $offset++) { $weekday = $future->addDays($offset)->dayOfWeek; $predictions[] = $counts[$weekday] > 0 ? $totals[$weekday] / $counts[$weekday] : max(0.0, $fallbackDailyRate); }
        return $predictions;
    }

    private function exponentialLevel(Collection $dailySeries, float $fallbackDailyRate, float $alpha = 0.3): float
    {
        $alpha = min(1.0, max(0.01, $alpha));
        $values = $dailySeries->map(fn ($value): float => max(0.0, (float) $value))->values();
        $level = $values->first() ?? max(0.0, $fallbackDailyRate);
        foreach ($values->skip(1) as $value) $level = $alpha * (float) $value + (1 - $alpha) * $level;
        return max(0.0, (float) $level);
    }

    /** @param array<int, float> $predictions @return array{mae: float, rmse: float, mape: ?float, wape: ?float, bias: float} */
    private function errorMetrics(Collection $actual, array $predictions): array
    {
        $values = $actual->values();
        if ($values->isEmpty()) return ['mae' => 0.0, 'rmse' => 0.0, 'mape' => null, 'wape' => null, 'bias' => 0.0];
        $absolute = []; $squared = []; $percentage = []; $bias = 0.0; $actualTotal = 0.0;
        foreach ($values as $index => $value) {
            $observed = max(0.0, (float) $value); $predicted = max(0.0, (float) ($predictions[$index] ?? 0)); $error = $predicted - $observed;
            $absolute[] = abs($error); $squared[] = $error ** 2; $bias += $error; $actualTotal += $observed;
            if ($observed > 0.000001) $percentage[] = abs($error) / $observed * 100;
        }
        return ['mae' => round(array_sum($absolute) / count($absolute), 6), 'rmse' => round(sqrt(array_sum($squared) / count($squared)), 6), 'mape' => $percentage ? round(array_sum($percentage) / count($percentage), 6) : null, 'wape' => $actualTotal > 0.000001 ? round(array_sum($absolute) / $actualTotal * 100, 6) : null, 'bias' => round($bias / count($absolute), 6)];
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
