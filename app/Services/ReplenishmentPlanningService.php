<?php

namespace App\Services;

use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductPrice;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

class ReplenishmentPlanningService
{
    public function proposalsForCompany(int $companyId, ?int $productId = null, ?int $locationId = null, ?int $forecastHorizonDays = null): Collection
    {
        $products = Product::withoutGlobalScope('company')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('status', 1)->whereHas('supplier', fn ($query) => $query->where('is_active', true)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->when($productId, fn ($query, $id) => $query->whereKey($id))->with('supplier')->get();
        $forecastByProduct = $forecastHorizonDays === null ? collect() : collect(app(DemandForecastPlanningService::class)->forecastsForCompany($companyId, 90, max(1, min(365, $forecastHorizonDays)), $productId, $locationId)['forecasts'])->keyBy(fn (array $row): int => (int) $row['product']->id);
        $proposals = collect();
        foreach ($products as $product) {
            $policies = InventoryReplenishmentPolicy::with('location')->where('product_id', $product->id)->where('is_active', true)->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->when($locationId, fn ($query, $id) => $query->where('location_id', $id))->get();
            if ($locationId && $policies->isEmpty()) continue;
            $required = 0.0; $leadTime = 0; $safetyTime = 0; $planningLocation = null; $planningLocationCode = null; $currentStock = 0.0; $targetStock = null; $policySafetyStock = 0.0;
            if ($policies->isEmpty()) {
                $current = app(InventoryAvailabilityService::class)->available($product, true, null, $companyId);
                $currentStock = $current; $targetStock = (float) $product->reorder_level;
                $required = max(0, (float) $product->reorder_level - $current);
            } else {
                foreach ($policies as $policy) {
                    $current = $this->locationBalance($product, (int) $policy->location_id, $companyId);
                    $currentStock += $current;
                    if ($current <= (float) $policy->reorder_point) {
                        $safetyStock = $this->effectiveSafetyStock($product, $policy, $companyId);
                        $target = $policy->max_stock !== null
                            ? (float) $policy->max_stock
                            : ($policy->min_stock !== null ? (float) $policy->min_stock : max((float) $policy->reorder_point + $safetyStock, 1));
                        $targetStock = ($targetStock ?? 0) + $target;
                        $required += max(0, $target - $current);
                    }
                    $policySafetyStock += $this->effectiveSafetyStock($product, $policy, $companyId);
                    $leadTime = max($leadTime, (int) $policy->lead_time_days); $safetyTime = max($safetyTime, (int) ($policy->safety_time_days ?? 0)); $planningLocation = $policy->location_id; $planningLocationCode = $policy->location?->code;
                }
            }
            $forecast = $forecastByProduct->get((int) $product->id);
            $forecastQuantity = $forecast ? (float) $forecast['forecast_qty'] : null;
            if ($forecastQuantity !== null) $required = max($required, max(0, $forecastQuantity + $policySafetyStock - $currentStock));
            if ($required <= 0 || !$product->supplier_id) continue;
            $open = (float) PurchaseOrderLine::where('product_id', $product->id)->whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))->selectRaw('COALESCE(SUM(ordered_qty - received_qty), 0) AS quantity')->value('quantity');
            $quantity = max(0, $required - $open);
            if ($quantity <= 0) continue;
            $price = app(SupplierProductPriceService::class)->bestFor($product->supplier, $product, $quantity, now()->toDateString(), null)?->unit_price;
            $planningDays = $leadTime + $safetyTime;
            $orderDate = CarbonImmutable::now()->startOfDay();
            $receiptDate = app(PlanningCalendarService::class)->addWorkingDaysForSupplier($orderDate, $planningDays, $product->supplier, $companyId);
            $proposals->push(['product' => $product, 'product_id' => (int) $product->id, 'supplier_id' => (int) $product->supplier_id, 'quantity' => $quantity, 'open_purchase_quantity' => $open, 'current_stock' => $currentStock, 'target_stock' => $targetStock, 'forecast_quantity' => $forecastQuantity, 'forecast_confidence_low' => $forecast['confidence_low'] ?? null, 'forecast_confidence_high' => $forecast['confidence_high'] ?? null, 'unit_price' => (float) ($price ?? $product->purchase_price ?? 0), 'lead_time_days' => $leadTime, 'safety_time_days' => $safetyTime, 'planning_days' => $planningDays, 'suggested_order_date' => $orderDate->toDateString(), 'expected_receipt_date' => $receiptDate->toDateString(), 'location_id' => $planningLocation, 'location_code' => $planningLocationCode]);
        }
        return $proposals;
    }

    private function locationBalance(Product $product, int $locationId, int $companyId): float
    {
        return app(InventoryAvailabilityService::class)->available($product, true, $locationId, $companyId);
    }

    private function effectiveSafetyStock(Product $product, InventoryReplenishmentPolicy $policy, int $companyId): float
    {
        if ($policy->safety_stock_method !== 'variability') return (float) $policy->safety_stock;
        $days = 90;
        $from = now()->subDays($days - 1)->startOfDay();
        $daily = collect(range(0, $days - 1))->map(function (int $day) use ($product, $policy, $companyId, $from): float {
            $date = $from->copy()->addDays($day);
            return (float) InventoryMovement::where('product_id', $product->id)->where('location_id', $policy->location_id)->where('movement_type', 'issue')->whereBetween('posted_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->sum('quantity');
        });
        $mean = (float) $daily->avg();
        $variance = $daily->count() > 1 ? $daily->sum(fn (float $value): float => ($value - $mean) ** 2) / ($daily->count() - 1) : 0.0;
        return max(0, (float) ($policy->service_level_z ?: 1.65) * sqrt($variance) * sqrt(max(1, (int) $policy->lead_time_days)));
    }
}
