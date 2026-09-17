<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\InventoryLocation;
use App\Models\InventoryReplenishmentPolicy;
use App\Services\BomExplosionService;
use App\Models\InventoryBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\SupplierProductPriceService;
use App\Services\ReplenishmentPlanningService;
use App\Services\ProductionSuggestionService;

class PlanningController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'low');
        abort_unless(in_array($type, ['low', 'excess', 'slow', 'dead'], true), 404);
        $validated = $request->validate(['type' => ['nullable', 'in:low,excess,slow,dead'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'location_id' => ['nullable', 'integer']]);
        $defaultDays = $type === 'dead' ? app(\App\Services\ErpSettingService::class)->get('dead_stock_days', 180) : app(\App\Services\ErpSettingService::class)->get('slow_moving_days', 90);
        $days = (int) ($validated['days'] ?? $defaultDays);
        $cutoff = now()->subDays($days);
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        $locationId = $validated['location_id'] ?? null;
        if ($locationId !== null && !InventoryLocation::whereKey($locationId)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }
        $locations = InventoryLocation::with('warehouse')->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->where('is_active', true)->orderBy('code')->get();
        $allProducts = Product::with(['supplier', 'unit', 'category'])->get();
        $availability = app(\App\Services\InventoryAvailabilityService::class)->availableMany($allProducts, true, $locationId, $companyId);
        $policies = $locationId === null ? collect() : InventoryReplenishmentPolicy::where('location_id', $locationId)->where('is_active', true)->whereIn('product_id', $allProducts->pluck('id'))->get()->keyBy('product_id');
        $recentIssueProductIds = in_array($type, ['slow', 'dead'], true)
            ? InventoryMovement::whereIn('product_id', $allProducts->pluck('id'))->where('movement_type', 'issue')->where('posted_at', '>=', $cutoff)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->pluck('product_id')->unique()
            : collect();
        $matching = $allProducts->filter(function (Product $product) use ($type, $availability, $recentIssueProductIds, $policies): bool {
            $current = (float) ($availability[$product->id] ?? 0);
            $policy = $policies->get($product->id);
            $reorder = (float) ($policy?->reorder_point ?? $product->reorder_level);
            $maximum = $policy?->max_stock !== null ? (float) $policy->max_stock : ($product->max_stock === null ? null : (float) $product->max_stock);
            return match ($type) {
                'low' => $current <= $reorder,
                'excess' => $maximum !== null && $current > $maximum,
                'slow' => !$recentIssueProductIds->contains($product->id),
                'dead' => !$recentIssueProductIds->contains($product->id) && $current > 0,
            };
        })->map(function (Product $product) use ($availability, $policies, $locationId): Product {
            $current = (float) ($availability[$product->id] ?? 0); $excess = $product->max_stock === null ? 0 : max(0, $current - (float) $product->max_stock);
            $policy = $policies->get($product->id);
            $maximum = $policy?->max_stock !== null ? (float) $policy->max_stock : ($product->max_stock === null ? null : (float) $product->max_stock);
            $excess = $maximum === null ? 0 : max(0, $current - $maximum);
            $product->setAttribute('current_stock', $current); $product->setAttribute('planning_reorder_level', (float) ($policy?->reorder_point ?? $product->reorder_level));
            $product->setAttribute('planning_min_stock', $policy?->min_stock === null ? $product->min_stock : (float) $policy->min_stock);
            $product->setAttribute('planning_max_stock', $maximum); $product->setAttribute('planning_location_id', $locationId);
            $product->setAttribute('excess_quantity', $excess); $product->setAttribute('excess_value', $excess * (float) ($product->purchase_price ?? 0));
            return $product;
        })->sortByDesc('current_stock')->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $products = new LengthAwarePaginator($matching->forPage($page, 50)->values(), $matching->count(), 50, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        $products->withQueryString();
        return view('backend.stock.planning_report', compact('products', 'type', 'cutoff', 'days', 'locations', 'locationId'));
    }

    public function purchaseSuggestions(Request $request)
    {
        $data = $request->validate(['forecast_horizon_days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $forecastHorizon = $data['forecast_horizon_days'] ?? null;
        $items = app(ReplenishmentPlanningService::class)
            ->proposalsForCompany((int) auth()->user()?->company_id, null, null, $forecastHorizon)
            ->map(function (array $proposal): Product {
                $product = clone $proposal['product'];
                $product->setAttribute('quantity', $proposal['quantity']);
                $product->setAttribute('current_stock', $proposal['current_stock']);
                $product->setAttribute('target_stock', $proposal['target_stock']);
                $product->setAttribute('planning_location', $proposal['location_code']);
                $product->setAttribute('open_purchase_quantity', $proposal['open_purchase_quantity']);
                $product->setAttribute('planning_days', $proposal['planning_days']);
                $product->setAttribute('expected_receipt_date', $proposal['expected_receipt_date']);
                $product->setAttribute('forecast_quantity', $proposal['forecast_quantity']);
                $product->setAttribute('forecast_confidence_low', $proposal['forecast_confidence_low']);
                $product->setAttribute('forecast_confidence_high', $proposal['forecast_confidence_high']);
                return $product;
            });
        $page = LengthAwarePaginator::resolveCurrentPage();
        $suggestions = new LengthAwarePaginator($items->forPage($page, 50)->values(), $items->count(), 50, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);

        return view('backend.stock.purchase_suggestions', compact('suggestions', 'forecastHorizon'));
    }

    public function createPurchaseOrders(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', 'distinct'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['required', 'numeric', 'gt:0'],
        ]);

        $products = Product::with('supplier')->whereIn('id', $data['product_id'])->get()->keyBy('id');
        if ($products->count() !== count($data['product_id'])) return back()->with(['message' => 'One or more selected products are outside the current company.', 'alert-type' => 'error']);

        try {
            $orders = DB::transaction(function () use ($data, $products): Collection {
                $grouped = collect($data['product_id'])->map(function ($productId) use ($data, $products): array {
                    $product = $products->get((int) $productId);
                    if (!$product?->supplier_id) throw new \RuntimeException('A supplier is required for '.$product->name.' before creating a purchase order.');
                    return ['product' => $product, 'quantity' => (float) ($data['quantity'][$productId] ?? 0)];
                })->groupBy(fn (array $row): int => (int) $row['product']->supplier_id);

                return $grouped->map(function (Collection $lines, int $supplierId): PurchaseOrder {
                    $order = PurchaseOrder::create([
                        'company_id' => auth()->user()?->company_id,
                        'supplier_id' => $supplierId,
                        'date' => now()->toDateString(),
                        'description' => 'Generated from approved replenishment suggestions.',
                        'currency_code' => strtoupper(auth()->user()?->company?->base_currency ?? 'USD'),
                        'exchange_rate' => 1,
                        'po_no' => app(\App\Services\NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                        'created_by' => auth()->id(),
                        'status' => 'submitted',
                    ]);
                    foreach ($lines->groupBy(fn (array $row): int => (int) $row['product']->id) as $productLines) {
                        $product = $productLines->first()['product'];
                        $quantity = (float) $productLines->sum('quantity');
                        $price = app(SupplierProductPriceService::class)->bestFor($order->supplier, $product, $quantity, now()->toDateString(), $order->currency_code)?->unit_price;
                        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'ordered_qty' => $quantity, 'unit_price' => (float) ($price ?? $product->purchase_price ?? 0)]);
                    }
                    app(\App\Services\AuditService::class)->record('purchase_order.created_from_replenishment', $order, null, $order->toArray());
                    return $order;
                });
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error'])->withInput();
        }

        return redirect()->route('procurement.orders')->with(['message' => $orders->count().' purchase order(s) submitted for approval.', 'alert-type' => 'success']);
    }

    public function productionSuggestions()
    {
        $suggestions = app(ProductionSuggestionService::class)->forCompany((int) auth()->user()?->company_id);
        return view('backend.stock.production_suggestions', compact('suggestions'));
    }

    public function mrp()
    {
        $result = app(\App\Services\MrpPlanningService::class)->proposalsForCompany((int) auth()->user()?->company_id);
        $proposals = $result['proposals']; $errors = $result['errors'];
        return view('backend.stock.mrp_report', compact('proposals', 'errors'));
    }

    public function dashboard()
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        $dashboardProducts = Product::where('status', 1)->get();
        $dashboardAvailability = app(\App\Services\InventoryAvailabilityService::class)->availableMany($dashboardProducts, true, null, $companyId);
        $planningCounts = app(\App\Services\DashboardMetricsService::class)->thresholdCounts($dashboardProducts, $companyId, $dashboardAvailability);
        $lowStock = $planningCounts['low']; $excessStock = $planningCounts['excess'];
        $deadDays = (int) app(\App\Services\ErpSettingService::class)->get('dead_stock_days', 180, $companyId);
        $expiryDays = (int) app(\App\Services\ErpSettingService::class)->get('expiry_alert_days', 90, $companyId);
        $recentlyIssued = InventoryMovement::whereIn('product_id', $dashboardProducts->pluck('id'))->where('movement_type', 'issue')->where('posted_at', '>=', now()->subDays($deadDays))->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->distinct()->pluck('product_id');
        $deadStock = $dashboardProducts->filter(fn (Product $product): bool => ($dashboardAvailability[$product->id] ?? 0) > 0 && !$recentlyIssued->contains($product->id))->count();
        $expiringBatches = InventoryBatch::whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->addDays($expiryDays))->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->count();
        return view('backend.stock.planning_dashboard', compact('lowStock', 'excessStock', 'deadStock', 'expiringBatches', 'deadDays', 'expiryDays'));
    }
}
