<?php

namespace App\Http\Controllers;

use App\Models\DemandForecastOverride;
use App\Models\InventoryLocation;
use App\Models\Product;
use Illuminate\Http\Request;

class DemandForecastController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) auth()->user()?->company_id;
        $data = $request->validate(['history_days' => ['nullable', 'integer', 'min:7', 'max:730'], 'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'location_id' => ['nullable', 'integer'], 'seasonality' => ['nullable', 'in:none,weekly']]);
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) InventoryLocation::whereKey($locationId)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        $locations = InventoryLocation::with('warehouse')->where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->orderBy('code')->get();
        $result = app(\App\Services\DemandForecastPlanningService::class)->forecastsForCompany($companyId, (int) ($data['history_days'] ?? 90), (int) ($data['horizon_days'] ?? 30), null, $locationId, $data['seasonality'] ?? 'none');
        $forecasts = $result['forecasts']; $historyDays = $result['history_days']; $horizonDays = $result['horizon_days']; $seasonality = $result['seasonality']; $from = $result['from']; $to = $result['to']; $forecastFrom = $result['forecast_from']; $forecastTo = $result['forecast_to'];
        return view('backend.stock.demand_forecast', compact('forecasts', 'historyDays', 'horizonDays', 'seasonality', 'from', 'to', 'forecastFrom', 'forecastTo', 'locations', 'locationId'));
    }

    public function storeOverride(Request $request)
    {
        $companyId = (int) auth()->user()?->company_id;
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'location_id' => ['nullable', 'integer'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'forecast_quantity' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (!empty($data['location_id'])) InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        $product = Product::findOrFail($data['product_id']);
        $override = DemandForecastOverride::updateOrCreate(['company_id' => $companyId, 'product_id' => $product->id, 'location_id' => $data['location_id'] ?? null, 'period_start' => $data['period_start'], 'period_end' => $data['period_end']], $data + ['created_by' => auth()->id()]);
        app(\App\Services\AuditService::class)->record('demand_forecast.override_saved', $override, null, $override->toArray());
        return back()->with(['message' => 'Forecast override saved for '.$product->name.'.', 'alert-type' => 'success']);
    }
}
