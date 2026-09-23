<?php

namespace App\Services;

use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductPrice;
use App\Models\InventoryMovement;
use App\Models\InventoryLocation;
use App\Models\InventoryTransfer;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

class ReplenishmentPlanningService
{
    /** Return a read-only network replenishment view by product and policy node. */
    public function multiEchelonForCompany(int $companyId, ?int $productId = null): Collection
    {
        $policies = InventoryReplenishmentPolicy::with(['product', 'location'])
            ->where('is_active', true)
            ->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->when($productId, fn ($query, $id) => $query->where('product_id', $id))
            ->get()
            ->filter(fn (InventoryReplenishmentPolicy $policy): bool => $policy->product !== null && (int) $policy->product->status === 1)
            ->groupBy('product_id');
        if ($policies->isEmpty()) return collect();

        $openTransferRows = InventoryTransfer::withoutGlobalScopes()->with('lines')
            ->where('company_id', $companyId)->whereIn('status', ['pending', 'approved', 'in_transit', 'partially_received'])
            ->get()->flatMap(fn (InventoryTransfer $transfer) => $transfer->lines->map(function ($line) use ($transfer): array {
                return ['product_id' => (int) $line->product_id, 'destination_location_id' => (int) $line->destination_location_id, 'source_location_id' => (int) $line->source_location_id, 'quantity' => max(0, (float) $line->quantity - (float) ($line->received_quantity ?? 0)), 'outbound_committed' => in_array($transfer->status, ['pending', 'approved'], true)];
            }))->filter(fn (array $row): bool => $row['quantity'] > 0.000001);
        $openPurchaseRows = PurchaseOrderLine::whereIn('product_id', $policies->keys())
            ->whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
            ->selectRaw('product_id, COALESCE(SUM(ordered_qty - received_qty), 0) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $transferSuggestions = app(TransferReplenishmentService::class)->suggestionsForCompany($companyId, $productId);
        $suggestedByProduct = $transferSuggestions->groupBy('product_id')->map(fn (Collection $rows): float => (float) $rows->sum('quantity'));
        $locations = InventoryLocation::withoutGlobalScopes()
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->get()->keyBy('id');

        return $policies->map(function (Collection $productPolicies, $groupProductId) use ($companyId, $openTransferRows, $openPurchaseRows, $suggestedByProduct, $locations): array {
            $product = $productPolicies->first()->product;
            $nodes = $productPolicies->map(function (InventoryReplenishmentPolicy $policy) use ($product, $companyId, $openTransferRows, $locations): array {
                $locationId = (int) $policy->location_id;
                $onHand = (float) app(InventoryAvailabilityService::class)->available($product, true, $locationId, $companyId);
                $inbound = (float) $openTransferRows->where('product_id', $product->id)->where('destination_location_id', $locationId)->sum('quantity');
                $outbound = (float) $openTransferRows->where('product_id', $product->id)->where('source_location_id', $locationId)->where('outbound_committed', true)->sum('quantity');
                $current = max(0, $onHand + $inbound - $outbound);
                $reorderPoint = $this->effectiveReorderPoint($product, $policy, $companyId);
                $safety = (float) $policy->safety_stock;
                $floor = $policy->min_stock !== null ? (float) $policy->min_stock : $reorderPoint;
                $target = $policy->max_stock !== null ? (float) $policy->max_stock : ($policy->min_stock !== null ? (float) $policy->min_stock : $reorderPoint + $safety);
                $location = $locations->get($locationId); $hierarchy = [];
                $visited = [];
                for ($cursor = $location; $cursor !== null && !in_array((int) $cursor->id, $visited, true); $cursor = $locations->get($cursor->parent_id)) {
                    $visited[] = (int) $cursor->id;
                    array_unshift($hierarchy, ['id' => (int) $cursor->id, 'name' => $cursor->name, 'code' => $cursor->code, 'type' => $cursor->type]);
                }
                return ['location_id' => $locationId, 'location_name' => $location?->name, 'location_code' => $location?->code, 'parent_location_id' => $location?->parent_id, 'hierarchy' => $hierarchy, 'on_hand' => round($onHand, 6), 'open_transfer_inbound' => round($inbound, 6), 'open_transfer_outbound' => round($outbound, 6), 'current_stock' => round($current, 6), 'reorder_point' => round($reorderPoint, 6), 'reorder_point_method' => $policy->reorder_point_method ?: 'fixed', 'target_stock' => round(max($target, $floor), 6), 'floor_stock' => round($floor, 6), 'shortage' => round(max(0, $target - $current), 6), 'surplus' => round(max(0, $current - $floor), 6)];
            })->values();
            $shortage = (float) $nodes->sum('shortage'); $surplus = (float) $nodes->sum('surplus');
            $transferQuantity = (float) $suggestedByProduct->get((int) $groupProductId, 0); $openPurchaseQuantity = max(0, (float) $openPurchaseRows->get($groupProductId, 0));
            return ['product_id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku, 'current_stock' => round((float) $nodes->sum('current_stock'), 6), 'target_stock' => round((float) $nodes->sum('target_stock'), 6), 'shortage' => round($shortage, 6), 'surplus' => round($surplus, 6), 'open_purchase_quantity' => round($openPurchaseQuantity, 6), 'transfer_suggestion_quantity' => round($transferQuantity, 6), 'external_purchase_requirement' => round(max(0, $shortage - $transferQuantity - $openPurchaseQuantity), 6), 'nodes' => $nodes];
        })->values();
    }

    public function proposalsForCompany(int $companyId, ?int $productId = null, ?int $locationId = null, ?int $forecastHorizonDays = null, bool $includeTransferCoverage = false): Collection
    {
        $products = Product::withoutGlobalScope('company')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('status', 1)->whereHas('supplier', fn ($query) => $query->where('is_active', true)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->when($productId, fn ($query, $id) => $query->whereKey($id))->with('supplier')->get();
        $transferCoverage = $includeTransferCoverage
            ? app(TransferReplenishmentService::class)->suggestionsForCompany($companyId, $productId)->groupBy('product_id')->map(fn (Collection $rows): float => (float) $rows->sum('quantity'))
            : collect();
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
                    $reorderPoint = $this->effectiveReorderPoint($product, $policy, $companyId);
                    if ($current <= $reorderPoint) {
                        $safetyStock = $this->effectiveSafetyStock($product, $policy, $companyId);
                        $target = $policy->max_stock !== null
                            ? (float) $policy->max_stock
                            : ($policy->min_stock !== null ? (float) $policy->min_stock : max($reorderPoint + $safetyStock, 1));
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
            $coveredByTransfer = min($required, (float) $transferCoverage->get((int) $product->id, 0));
            $required = max(0, $required - $coveredByTransfer);
            if ($required <= 0 || !$product->supplier_id) continue;
            $open = (float) PurchaseOrderLine::where('product_id', $product->id)
                ->whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
                ->when($locationId !== null, fn ($query) => $query->where(fn ($locationQuery) => $locationQuery->whereNull('location_id')->orWhere('location_id', $locationId)))
                ->selectRaw('COALESCE(SUM(ordered_qty - received_qty), 0) AS quantity')->value('quantity');
            $quantity = max(0, $required - $open);
            if ($quantity <= 0) continue;
            $price = app(SupplierProductPriceService::class)->bestFor($product->supplier, $product, $quantity, now()->toDateString(), null)?->unit_price;
            $planningDays = $leadTime + $safetyTime;
            $orderDate = CarbonImmutable::now()->startOfDay();
            $receiptDate = app(PlanningCalendarService::class)->addWorkingDaysForSupplier($orderDate, $planningDays, $product->supplier, $companyId);
            $proposals->push(['product' => $product, 'product_id' => (int) $product->id, 'supplier_id' => (int) $product->supplier_id, 'quantity' => $quantity, 'open_purchase_quantity' => $open, 'open_transfer_quantity' => $coveredByTransfer, 'current_stock' => $currentStock, 'target_stock' => $targetStock, 'forecast_quantity' => $forecastQuantity, 'forecast_confidence_low' => $forecast['confidence_low'] ?? null, 'forecast_confidence_high' => $forecast['confidence_high'] ?? null, 'unit_price' => (float) ($price ?? $product->purchase_price ?? 0), 'lead_time_days' => $leadTime, 'safety_time_days' => $safetyTime, 'planning_days' => $planningDays, 'suggested_order_date' => $orderDate->toDateString(), 'expected_receipt_date' => $receiptDate->toDateString(), 'location_id' => $planningLocation, 'location_code' => $planningLocationCode]);
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

    public function effectiveReorderPoint(Product $product, InventoryReplenishmentPolicy $policy, int $companyId): float
    {
        if (($policy->reorder_point_method ?: 'fixed') !== 'demand') return (float) $policy->reorder_point;
        $days = max(7, min(730, (int) ($policy->reorder_history_days ?: 90)));
        $from = now()->subDays($days - 1)->startOfDay();
        $issues = (float) InventoryMovement::where('product_id', $product->id)->where('location_id', $policy->location_id)
            ->where('movement_type', 'issue')->whereBetween('posted_at', [$from, now()->endOfDay()])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->sum('quantity');
        return max(0, ($issues / $days) * max(0, (int) $policy->lead_time_days) + $this->effectiveSafetyStock($product, $policy, $companyId));
    }
}
