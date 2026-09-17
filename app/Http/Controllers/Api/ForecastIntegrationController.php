<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemandForecastOverride;
use App\Models\Product;
use App\Models\InventoryLocation;
use App\Services\AuditService;
use App\Services\DemandForecastPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ForecastIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['history_days' => ['nullable', 'integer', 'min:7', 'max:730'], 'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'seasonality' => ['nullable', 'in:none,weekly']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for demand forecasting.');
        if (!empty($data['location_id'])) InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        $result = app(DemandForecastPlanningService::class)->forecastsForCompany((int) $companyId, (int) ($data['history_days'] ?? 90), (int) ($data['horizon_days'] ?? 30), $data['product_id'] ?? null, $data['location_id'] ?? null, $data['seasonality'] ?? 'none');
        return response()->json(['data' => $result['forecasts'], 'meta' => collect($result)->except('forecasts')->all()]);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'location_id' => ['nullable', 'integer'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'forecast_quantity' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (!empty($data['location_id'])) InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
        $product = Product::findOrFail($data['product_id']);
        $override = DemandForecastOverride::updateOrCreate(['company_id' => $companyId, 'product_id' => $product->id, 'location_id' => $data['location_id'] ?? null, 'period_start' => $data['period_start'], 'period_end' => $data['period_end']], $data + ['created_by' => $request->user()?->id]);
        app(AuditService::class)->record('demand_forecast.override_saved', $override, null, $override->toArray());
        return response()->json(['data' => $override->load('product'), 'status' => 'saved']);
    }
}
