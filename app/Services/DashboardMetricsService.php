<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryAdjustment;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\InventoryDocument;
use App\Models\Payment;
use App\Models\Product;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Purchase;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockCount;
use App\Models\CustomerPaymentAllocation;
use App\Models\ServiceRequest;
use App\Models\MaintenanceOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardMetricsService
{
    public function forCurrentCompany(int $months = 6): array
    {
        $months = max(3, min(24, $months));
        $companyId = auth()->user()?->company_id ?: 'global';
        $ttl = (int) config('erp.dashboard_cache_ttl', 60);
        if ($ttl === 0) return $this->calculate($months);
        return Cache::remember('erp.dashboard.metrics.'.$companyId.'.'.$months, now()->addSeconds($ttl), fn (): array => $this->calculate($months));
    }

    public function forUser(User $user, int $months = 6): array
    {
        $metrics = $this->forCurrentCompany($months);
        $hasAssignedRoles = $user->roles()->exists();
        $finance = !$hasAssignedRoles || $user->hasPermission('reports.view') || $user->hasPermission('accounting.view');
        $service = !$hasAssignedRoles || $user->hasPermission('reports.view') || $user->hasPermission('service.manage');
        $metrics['visibility'] = ['finance' => $finance, 'service' => $service, 'inventory' => true];
        if (!$finance) {
            $metrics['month_sales'] = null;
            $metrics['receivables'] = null;
            $metrics['sales_trend'] = [];
        }
        if (!$service) {
            $metrics['open_service_requests'] = null;
            $metrics['breached_service_requests'] = null;
            $metrics['active_maintenance_orders'] = null;
        }
        return $metrics;
    }

    private function calculate(int $months = 6): array
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        $settings = app(ErpSettingService::class);
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthSales = (float) Invoice::whereIn('status', [1, 'approved'])->whereDate('date', '>=', $monthStart)->sum('total_amount');
        $products = Product::where('status', 1)->get(); $availability = app(InventoryAvailabilityService::class)->availableMany($products, true, null, auth()->user()?->company_id);
        $totalProducts = max(1, $products->count()); $planning = $this->planningMetrics($products, $companyId, $availability); $lowStock = $planning['low'];
        $pendingApprovals = Invoice::where('status', 0)->count() + Purchase::where('status', 0)->count() + InventoryAdjustment::where('status', 'pending')->count() + PurchaseOrder::where('status', 'submitted')->count() + GoodsReceipt::where('status', 'pending')->count() + PurchaseInvoice::where('status', 'pending')->count() + SalesOrder::where('status', 'submitted')->count() + Delivery::where('status', 'pending')->count() + InventoryReturn::where('status', 'pending')->count() + InventoryDocument::where('status', 'pending')->count() + StockCount::where('status', 'submitted')->count();
        $receivables = max(0, (float) Payment::where('due_amount', '>', 0)->where('approval_status', 'approved')->where('is_reversed', false)->sum('due_amount') - (float) CustomerPaymentAllocation::whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('approval_status', 'approved')->where('is_reversed', false))->sum('amount'));
        $openServiceRequests = ServiceRequest::whereIn('status', ['open', 'assigned', 'in_progress'])->count();
        $breachedServiceRequests = ServiceRequest::whereNotNull('response_due_at')->whereNotIn('status', ['resolved', 'cancelled'])->where(function ($query): void { $query->where(function ($nested): void { $nested->whereNull('assigned_at')->where('response_due_at', '<', now()); })->orWhereColumn('assigned_at', '>', 'response_due_at'); })->count();
        $activeMaintenanceOrders = MaintenanceOrder::whereIn('status', ['planned', 'in_progress'])->count();
        return [
            'month_sales' => $monthSales, 'total_products' => $totalProducts, 'low_stock' => $lowStock,
            'pending_approvals' => $pendingApprovals, 'receivables' => $receivables,
            'open_service_requests' => $openServiceRequests, 'breached_service_requests' => $breachedServiceRequests,
            'active_maintenance_orders' => $activeMaintenanceOrders,
            'sales_trend' => $this->salesTrend($months),
            'exception_drilldowns' => ['low_stock' => $planning['low_rows'], 'excess_stock' => $planning['excess_rows']],
            'planning_windows' => [
                'slow_moving_days' => (int) $settings->get('slow_moving_days', 90, $companyId),
                'dead_stock_days' => (int) $settings->get('dead_stock_days', 180, $companyId),
                'expiry_alert_days' => (int) $settings->get('expiry_alert_days', 90, $companyId),
            ],
            'capacity_alert_threshold_percent' => (float) $settings->get('warehouse_capacity_alert_percent', 80, $companyId),
            'abc_thresholds_percent' => [
                'a' => (float) $settings->get('abc_a_threshold_percent', 80, $companyId),
                'b' => (float) $settings->get('abc_b_threshold_percent', 95, $companyId),
            ],
        ];
    }

    private function salesTrend(int $months): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);
        return collect(range(0, $months - 1))->map(function (int $offset) use ($start): array {
            $month = $start->copy()->addMonths($offset);
            return ['month' => $month->format('Y-m'), 'sales' => (float) Invoice::whereIn('status', [1, 'approved'])->whereBetween('date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])->sum('total_amount')];
        })->values()->all();
    }

    private function planningMetrics($products, int $companyId, array $globalAvailability): array
    {
        $products = collect($products);
        $policies = InventoryReplenishmentPolicy::where('is_active', true)->whereIn('product_id', $products->pluck('id'))->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->get()->groupBy('product_id');
        $locationAvailability = [];
        $availabilityService = app(InventoryAvailabilityService::class);
        foreach ($policies->flatten()->pluck('location_id')->unique() as $locationId) $locationAvailability[(int) $locationId] = $availabilityService->availableMany($products, true, (int) $locationId, $companyId);
        $planningService = app(ReplenishmentPlanningService::class);
        $lowRows = collect(); $excessRows = collect();
        foreach ($products as $product) {
            $productPolicies = $policies->get($product->id, collect());
            if ($productPolicies->isEmpty()) {
                $this->appendPlanningException($lowRows, $excessRows, $product, (float) ($globalAvailability[$product->id] ?? 0), (float) ($product->reorder_level ?? 0), $product->max_stock === null ? null : (float) $product->max_stock, null);
                continue;
            }
            foreach ($productPolicies as $policy) $this->appendPlanningException($lowRows, $excessRows, $product, (float) ($locationAvailability[(int) $policy->location_id][$product->id] ?? 0), $planningService->effectiveReorderPoint($product, $policy, $companyId), $policy->max_stock === null ? null : (float) $policy->max_stock, (int) $policy->location_id);
        }
        $low = $lowRows->pluck('product_id')->unique()->count(); $excess = $excessRows->pluck('product_id')->unique()->count();
        return ['low' => $low, 'excess' => $excess, 'low_rows' => $lowRows->sortBy('available_quantity')->take(10)->values()->all(), 'excess_rows' => $excessRows->sortByDesc('excess_quantity')->take(10)->values()->all()];
    }

    private function appendPlanningException($lowRows, $excessRows, Product $product, float $available, float $reorder, ?float $maximum, ?int $locationId): void
    {
        $base = ['product_id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku, 'location_id' => $locationId, 'available_quantity' => $available, 'reorder_level' => $reorder, 'max_stock' => $maximum];
        if ($available <= $reorder) $lowRows->push($base + ['shortage_quantity' => max(0, $reorder - $available)]);
        if ($maximum !== null && $available > $maximum) $excessRows->push($base + ['excess_quantity' => $available - $maximum, 'excess_value' => ($available - $maximum) * (float) ($product->purchase_price ?? 0)]);
    }

    /**
     * Return product-level planning counts while honoring active location policies.
     * A product is counted once when any applicable location is below/above policy.
     * Products without a location policy retain the legacy product-wide behavior.
     */
    public function thresholdCounts($products, int $companyId, ?array $globalAvailability = null): array
    {
        $products = collect($products);
        $globalAvailability ??= app(InventoryAvailabilityService::class)->availableMany($products, true, null, $companyId);
        $policies = InventoryReplenishmentPolicy::where('is_active', true)
            ->whereIn('product_id', $products->pluck('id'))
            ->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->get()->groupBy('product_id');
        $locationAvailability = [];
        $availabilityService = app(InventoryAvailabilityService::class);
        foreach ($policies->flatten()->pluck('location_id')->unique() as $locationId) {
            $locationAvailability[(int) $locationId] = $availabilityService->availableMany($products, true, (int) $locationId, $companyId);
        }
        $low = 0; $excess = 0;
        foreach ($products as $product) {
            $productPolicies = $policies->get($product->id, collect());
            if ($productPolicies->isEmpty()) {
                $current = (float) ($globalAvailability[$product->id] ?? 0);
                if ($current <= (float) $product->reorder_level) $low++;
                if ($product->max_stock !== null && $current > (float) $product->max_stock) $excess++;
                continue;
            }
            $isLow = false; $isExcess = false;
            $planning = app(\App\Services\ReplenishmentPlanningService::class);
            foreach ($productPolicies as $policy) {
                $current = (float) ($locationAvailability[(int) $policy->location_id][$product->id] ?? 0);
                $isLow = $isLow || $current <= $planning->effectiveReorderPoint($product, $policy, $companyId);
                $isExcess = $isExcess || ($policy->max_stock !== null && $current > (float) $policy->max_stock);
            }
            if ($isLow) $low++;
            if ($isExcess) $excess++;
        }
        return ['low' => $low, 'excess' => $excess];
    }
}
