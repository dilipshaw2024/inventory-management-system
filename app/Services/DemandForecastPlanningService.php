<?php

namespace App\Services;

use App\Models\DemandForecastOverride;
use App\Models\InvoiceDetail;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Collection;

class DemandForecastPlanningService
{
    public function forecastsForCompany(int $companyId, int $historyDays = 90, int $horizonDays = 30, ?int $productId = null, ?int $locationId = null, string $seasonality = 'none'): array
    {
        $historyDays = max(7, min(730, $historyDays)); $horizonDays = max(1, min(365, $horizonDays));
        $from = now()->subDays($historyDays - 1)->toDateString(); $to = now()->toDateString();
        $forecastFrom = now()->addDay()->toDateString(); $forecastTo = now()->addDays($horizonDays)->toDateString();
        $sales = $locationId === null
            ? InvoiceDetail::where('status', 1)->whereBetween('date', [$from, $to])->whereHas('invoice', fn ($query) => $query->where(function ($scope) use ($companyId): void { $scope->where('company_id', $companyId)->orWhereNull('company_id'); }))->get()->groupBy('product_id')
            : InventoryMovement::where('movement_type', 'issue')->where('location_id', $locationId)->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->groupBy('product_id');
        $products = Product::withoutGlobalScope('company')->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->where('status', 1)->when($productId, fn ($query, $id) => $query->whereKey($id))->with('unit')->orderBy('name')->get();
        $availability = $locationId === null
            ? app(InventoryAvailabilityService::class)->availableMany($products, true, null, $companyId)
            : $products->mapWithKeys(fn (Product $product): array => [$product->id => app(InventoryAvailabilityService::class)->available($product, true, $locationId, $companyId)])->all();
        $overrides = DemandForecastOverride::where('company_id', $companyId)->whereDate('period_start', $forecastFrom)->whereDate('period_end', $forecastTo)->when($locationId === null, fn ($query) => $query->whereNull('location_id'), fn ($query) => $query->where('location_id', $locationId))->get()->keyBy('product_id');
        $forecasts = $products->map(function (Product $product) use ($sales, $historyDays, $horizonDays, $availability, $overrides, $from, $forecastFrom, $locationId, $seasonality): array {
            $productSales = $sales->get($product->id, collect()); $sold = (float) $productSales->sum($locationId === null ? 'selling_qty' : 'quantity'); $daily = $sold / max($historyDays, 1);
            $dailySeries = collect(range(0, $historyDays - 1))->map(function (int $day) use ($productSales, $from, $locationId): float { $date = now()->parse($from)->addDays($day)->toDateString(); return (float) $productSales->filter(fn ($row) => ($locationId === null ? (string) $row->date : optional($row->posted_at)->toDateString()) === $date)->sum($locationId === null ? 'selling_qty' : 'quantity'); });
            $override = $overrides->get($product->id); $forecast = $override ? (float) $override->forecast_quantity : ($seasonality === 'weekly' ? app(DemandForecastService::class)->seasonalForecast($dailySeries, now()->parse($from), now()->parse($forecastFrom), $horizonDays, $daily) : $daily * $horizonDays); $confidence = app(DemandForecastService::class)->confidenceBand($dailySeries, $daily, $horizonDays, $forecast); $available = (float) ($availability[$product->id] ?? 0);
            return ['product' => $product, 'location_id' => $locationId, 'historical_qty' => $sold, 'daily_rate' => $daily, 'forecast_qty' => $forecast, 'projected_balance' => $available - $forecast, 'reorder_level' => (float) $product->reorder_level, 'confidence_low' => $confidence['low'], 'confidence_high' => $confidence['high'], 'override' => $override];
        })->filter(fn (array $row): bool => $row['historical_qty'] > 0 || (float) ($availability[$row['product']->id] ?? 0) <= (float) $row['product']->reorder_level)->sortByDesc('forecast_qty')->values();
        return ['forecasts' => $forecasts, 'history_days' => $historyDays, 'horizon_days' => $horizonDays, 'seasonality' => $seasonality, 'from' => $from, 'to' => $to, 'forecast_from' => $forecastFrom, 'forecast_to' => $forecastTo];
    }
}
