<?php

namespace App\Http\Controllers;

use App\Models\DemandForecastOverride;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ReplenishmentScenario;
use Illuminate\Http\Request;

class DemandForecastController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) auth()->user()?->company_id;
        $data = $request->validate(['history_days' => ['nullable', 'integer', 'min:7', 'max:730'], 'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'location_id' => ['nullable', 'integer'], 'seasonality' => ['nullable', 'in:none,weekly,exponential,auto']]);
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

    public function scenario(Request $request)
    {
        $companyId = (int) auth()->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'demand_multiplier' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'daily_demand' => ['nullable', 'numeric', 'min:0'],
        ]);
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) InventoryLocation::whereKey($locationId)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        $locations = InventoryLocation::with('warehouse')->where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->orderBy('code')->get();
        $products = Product::withoutGlobalScope('company')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('status', 1)->orderBy('name')->get(['id', 'name', 'sku']);
        $savedScenarios = ReplenishmentScenario::with(['product:id,name,sku', 'location:id,name,code'])->where('company_id', $companyId)->latest('updated_at')->limit(25)->get();
        $horizonDays = (int) ($data['horizon_days'] ?? 30);
        $scenarios = app(\App\Services\ReplenishmentScenarioService::class)->simulate($companyId, $data['product_id'] ?? null, $locationId, $horizonDays, (float) ($data['demand_multiplier'] ?? 1), array_key_exists('daily_demand', $data) ? (float) $data['daily_demand'] : null);
        return view('backend.stock.replenishment_scenario', ['scenarios' => $scenarios, 'savedScenarios' => $savedScenarios, 'locations' => $locations, 'products' => $products, 'locationId' => $locationId, 'horizonDays' => $horizonDays, 'demandMultiplier' => (float) ($data['demand_multiplier'] ?? 1), 'dailyDemand' => array_key_exists('daily_demand', $data) ? (float) $data['daily_demand'] : null, 'productId' => $data['product_id'] ?? null]);
    }

    public function saveScenario(Request $request)
    {
        $companyId = (int) auth()->user()?->company_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'external_reference' => ['nullable', 'string', 'max:150'],
            'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'demand_multiplier' => ['required', 'numeric', 'min:0', 'max:10'], 'daily_demand' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (!empty($data['location_id'])) InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        if (!empty($data['product_id'])) Product::withoutGlobalScope('company')->whereKey($data['product_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        if (!empty($data['external_reference']) && ReplenishmentScenario::where('external_reference', $data['external_reference'])->exists()) return back()->with(['message' => 'This scenario reference already exists.', 'alert-type' => 'error']);
        $rows = app(\App\Services\ReplenishmentScenarioService::class)->simulate($companyId, $data['product_id'] ?? null, $data['location_id'] ?? null, (int) $data['horizon_days'], (float) $data['demand_multiplier'], array_key_exists('daily_demand', $data) ? (float) $data['daily_demand'] : null);
        $scenario = ReplenishmentScenario::create([
            'company_id' => $companyId, 'name' => $data['name'], 'external_reference' => $data['external_reference'] ?? null,
            'product_id' => $data['product_id'] ?? null, 'location_id' => $data['location_id'] ?? null, 'horizon_days' => $data['horizon_days'],
            'demand_multiplier' => $data['demand_multiplier'], 'daily_demand_override' => $data['daily_demand'] ?? null,
            'result_snapshot' => $rows->values()->all(), 'created_by' => auth()->id(),
        ]);
        app(\App\Services\AuditService::class)->record('replenishment_scenario.saved', $scenario, null, ['name' => $scenario->name, 'horizon_days' => $scenario->horizon_days, 'row_count' => $rows->count(), 'browser' => true]);
        return back()->with(['message' => 'Replenishment scenario saved.', 'alert-type' => 'success']);
    }
}
