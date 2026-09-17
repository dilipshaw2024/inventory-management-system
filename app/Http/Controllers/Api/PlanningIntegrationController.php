<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\ReplenishmentPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PlanningIntegrationController extends Controller
{
    public function exceptions(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:low,excess,slow,dead'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for planning exceptions.');
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) $this->assertOwnedLocation((int) $locationId, (int) $companyId);
        $defaultDays = $data['type'] === 'dead' ? app(\App\Services\ErpSettingService::class)->get('dead_stock_days', 180, (int) $companyId) : app(\App\Services\ErpSettingService::class)->get('slow_moving_days', 90, (int) $companyId);
        $days = (int) ($data['days'] ?? $defaultDays);
        $cutoff = now()->subDays($days);
        $perPage = (int) ($data['per_page'] ?? 50);
        $products = Product::with(['supplier', 'unit', 'category'])
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('id')->get();
        $availability = app(\App\Services\InventoryAvailabilityService::class)->availableMany($products, true, $locationId, (int) $companyId);
        $policies = $locationId === null ? collect() : InventoryReplenishmentPolicy::where('location_id', $locationId)->where('is_active', true)->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');
        $recentIssueProductIds = in_array($data['type'], ['slow', 'dead'], true)
            ? \App\Models\InventoryMovement::whereIn('product_id', $products->pluck('id'))->where('movement_type', 'issue')->where('posted_at', '>=', $cutoff)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->pluck('product_id')->unique()
            : collect();
        $matching = $products->filter(function (Product $product) use ($data, $availability, $policies, $recentIssueProductIds): bool {
            $current = (float) ($availability[$product->id] ?? 0); $policy = $policies->get($product->id);
            $reorder = (float) ($policy?->reorder_point ?? $product->reorder_level ?? 0);
            $maximum = $policy?->max_stock !== null ? (float) $policy->max_stock : ($product->max_stock === null ? null : (float) $product->max_stock);
            return match ($data['type']) {
                'low' => $current <= $reorder, 'excess' => $maximum !== null && $current > $maximum,
                'slow' => !$recentIssueProductIds->contains($product->id), 'dead' => !$recentIssueProductIds->contains($product->id) && $current > 0,
            };
        })->sortByDesc(fn (Product $product): float => (float) ($availability[$product->id] ?? 0))->values();
        $page = max(1, $request->integer('page', 1));
        $products = new LengthAwarePaginator($matching->forPage($page, $perPage)->values(), $matching->count(), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        $items = $products->getCollection()->map(function (Product $product) use ($availability, $policies, $locationId): array {
            $policy = $policies->get($product->id); $reorder = (float) ($policy?->reorder_point ?? $product->reorder_level ?? 0); $maximum = $policy?->max_stock !== null ? (float) $policy->max_stock : ($product->max_stock === null ? null : (float) $product->max_stock);
            $quantity = (float) ($availability[$product->id] ?? 0); $excessQuantity = $maximum === null ? 0 : max(0, $quantity - $maximum);
            return ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'reorder_level' => $reorder, 'min_stock' => $policy?->min_stock === null ? ($product->min_stock === null ? null : (float) $product->min_stock) : (float) $policy->min_stock, 'max_stock' => $maximum, 'excess_quantity' => $excessQuantity, 'unit_cost' => (float) ($product->purchase_price ?? 0), 'excess_value' => $excessQuantity * (float) ($product->purchase_price ?? 0), 'supplier_id' => $product->supplier_id, 'category_id' => $product->category_id, 'location_id' => $locationId];
        })->values();
        return response()->json(['data' => $items, 'meta' => ['type' => $data['type'], 'days' => $days, 'updated_since' => $data['updated_since'] ?? null, 'current_page' => $products->currentPage(), 'per_page' => $products->perPage(), 'total' => $products->total(), 'last_page' => $products->lastPage()] ]);
    }

    public function policies(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for replenishment policies.');
        $policies = InventoryReplenishmentPolicy::with(['product', 'location'])
            ->whereHas('location.warehouse.branch', fn ($q) => $q->where('company_id', $companyId))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when(array_key_exists('is_active', $data), fn ($q) => $q->where('is_active', $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($q, $date) => $q->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($policies, $request, 'planning.replenishment-policies', (int) ($data['per_page'] ?? 50));
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for replenishment policies.');
        $data = $request->validate($this->policyRules($companyId));
        $this->assertOwnedLocation((int) $data['location_id'], (int) $companyId);
        $this->assertOwnedProduct((int) $data['product_id'], (int) $companyId);
        $existing = !empty($data['external_reference']) ? InventoryReplenishmentPolicy::where('external_reference', $data['external_reference'])->where('product_id', $data['product_id'])->where('location_id', $data['location_id'])->first() : null;
        if ($existing) return response()->json(['data' => $existing->load('product', 'location'), 'status' => 'duplicate_ignored']);
        $policy = DB::transaction(function () use ($data): InventoryReplenishmentPolicy {
            $policy = InventoryReplenishmentPolicy::updateOrCreate(['product_id' => $data['product_id'], 'location_id' => $data['location_id']], $data + ['is_active' => true]);
            app(AuditService::class)->record('inventory_replenishment_policy.saved', $policy, null, $policy->toArray());
            return $policy;
        });
        return response()->json(['data' => $policy->load('product', 'location'), 'status' => 'saved'], 201);
    }

    public function updatePolicy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) ($request->user()?->company_id ?? 0); $policy = InventoryReplenishmentPolicy::with(['product', 'location'])->findOrFail($id);
        abort_unless($companyId, 403, 'A company is required for replenishment policies.');
        $this->assertOwnedLocation((int) $policy->location_id, $companyId);
        $this->assertOwnedProduct((int) $policy->product_id, $companyId);
        $data = $request->validate($this->policyRules($companyId, true));
        $this->assertOwnedLocation((int) ($data['location_id'] ?? $policy->location_id), $companyId);
        $this->assertOwnedProduct((int) ($data['product_id'] ?? $policy->product_id), $companyId);
        $targetProduct = (int) ($data['product_id'] ?? $policy->product_id); $targetLocation = (int) ($data['location_id'] ?? $policy->location_id);
        if (($data['product_id'] ?? null) !== null || ($data['location_id'] ?? null) !== null) {
            $conflict = InventoryReplenishmentPolicy::where('product_id', $targetProduct)->where('location_id', $targetLocation)->where('id', '<>', $policy->id)->exists();
            if ($conflict) abort(422, 'A replenishment policy already exists for this product and location.');
        }
        $effectiveMin = (float) ($data['min_stock'] ?? $policy->min_stock); if (array_key_exists('max_stock', $data) && $data['max_stock'] !== null && (float) $data['max_stock'] < $effectiveMin) abort(422, 'Maximum stock must be greater than or equal to minimum stock.');
        if (!empty($data['external_reference'])) {
            $referenceConflict = InventoryReplenishmentPolicy::where('external_reference', $data['external_reference'])->where('product_id', $targetProduct)->where('location_id', $targetLocation)->where('id', '<>', $policy->id)->exists();
            if ($referenceConflict) abort(422, 'The external reference is already assigned to another replenishment policy.');
        }
        $before = $policy->only(array_keys($data)); $policy->update($data); $after = $policy->fresh()->only(array_keys($data));
        app(AuditService::class)->record('inventory_replenishment_policy.updated', $policy, $before, $after);
        return response()->json(['data' => $policy->fresh()->load('product', 'location'), 'status' => 'updated']);
    }

    public function deactivatePolicy(int $id): JsonResponse
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0); abort_unless($companyId, 403, 'A company is required for replenishment policies.'); $policy = InventoryReplenishmentPolicy::with(['product', 'location'])->findOrFail($id);
        $this->assertOwnedLocation((int) $policy->location_id, $companyId); $this->assertOwnedProduct((int) $policy->product_id, $companyId);
        $before = $policy->only(['is_active']); $policy->update(['is_active' => false]);
        app(AuditService::class)->record('inventory_replenishment_policy.deactivated', $policy, $before, ['is_active' => false]);
        return response()->json(['data' => $policy->fresh()->load('product', 'location'), 'status' => 'deactivated']);
    }

    private function policyRules(?int $companyId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return ['external_reference' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:150'], 'product_id' => [$required, 'integer'], 'location_id' => [$required, 'integer'], 'reorder_point' => [$required, 'numeric', 'min:0'], 'safety_stock' => ['nullable', 'numeric', 'min:0'], 'safety_stock_method' => ['nullable', 'in:fixed,variability'], 'service_level_z' => ['nullable', 'numeric', 'gt:0', 'max:6'], 'min_stock' => ['nullable', 'numeric', 'min:0'], 'max_stock' => ['nullable', 'numeric', 'gte:min_stock'], 'lead_time_days' => ['nullable', 'integer', 'min:0'], 'safety_time_days' => ['nullable', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean']];
    }

    private function assertOwnedLocation(int $locationId, int $companyId): void
    {
        if (!InventoryLocation::withoutGlobalScopes()->whereKey($locationId)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists()) abort(422, 'Location is not authorized for this company.');
    }

    private function assertOwnedProduct(int $productId, int $companyId): void
    {
        if (!Product::withoutGlobalScope('company')->whereKey($productId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists()) abort(422, 'Product is not authorized for this company.');
    }

    public function replenishment(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'forecast_horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for replenishment planning.');
        if (!empty($data['location_id'])) $this->assertOwnedLocation((int) $data['location_id'], (int) $companyId);
        $proposals = app(ReplenishmentPlanningService::class)->proposalsForCompany((int) $companyId, $data['product_id'] ?? null, $data['location_id'] ?? null, $data['forecast_horizon_days'] ?? null);
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, $request->integer('page', 1)); $pageItems = $proposals->forPage($page, $perPage)->values();
        return response()->json(['data' => $pageItems, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $proposals->count(), 'last_page' => max(1, (int) ceil($proposals->count() / $perPage)), 'generated_at' => now()->toISOString()]]);
    }

    public function timePhasedReplenishment(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for time-phased replenishment planning.');
        if (!empty($data['location_id'])) $this->assertOwnedLocation((int) $data['location_id'], (int) $companyId);
        $horizon = (int) ($data['horizon_days'] ?? 30);
        $today = \Carbon\CarbonImmutable::today();
        $proposals = app(ReplenishmentPlanningService::class)->proposalsForCompany((int) $companyId, $data['product_id'] ?? null, $data['location_id'] ?? null, $horizon);
        $productIds = $proposals->pluck('product_id')->unique()->values();
        $openReceipts = PurchaseOrderLine::with('purchaseOrder')->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
            ->get()->mapToGroups(function (PurchaseOrderLine $line): array {
                $date = $line->purchaseOrder?->expected_date ?: $line->purchaseOrder?->date;
                return [$date?->toDateString() => ['product_id' => (int) $line->product_id, 'quantity' => max(0, (float) $line->ordered_qty - (float) $line->received_qty)]];
            });
        $rows = $proposals->map(function (array $proposal) use ($horizon, $today, $openReceipts): array {
            $dailyDemand = (float) ($proposal['forecast_quantity'] ?? 0) / max($horizon, 1);
            $balance = (float) $proposal['current_stock'];
            $target = $proposal['target_stock'] === null ? (float) ($proposal['product']->reorder_level ?? 0) : (float) $proposal['target_stock'];
            $buckets = collect(range(0, $horizon - 1))->map(function (int $offset) use (&$balance, $proposal, $dailyDemand, $today, $openReceipts, $target): array {
                $date = $today->addDays($offset)->toDateString();
                $open = (float) collect($openReceipts->get($date, []))->where('product_id', (int) $proposal['product_id'])->sum('quantity');
                $planned = $date === $proposal['expected_receipt_date'] ? (float) $proposal['quantity'] : 0.0;
                $opening = $balance; $balance = $balance + $open + $planned - $dailyDemand;
                return ['date' => $date, 'opening_balance' => round($opening, 6), 'demand' => round($dailyDemand, 6), 'open_purchase_receipts' => round($open, 6), 'planned_replenishment_receipts' => round($planned, 6), 'projected_balance' => round($balance, 6), 'projected_shortfall' => round(max(0, $target - $balance), 6), 'status' => $balance < 0 ? 'stockout' : ($balance <= $target ? 'reorder' : 'covered')];
            })->values();
            return ['product_id' => (int) $proposal['product_id'], 'supplier_id' => (int) $proposal['supplier_id'], 'location_id' => $proposal['location_id'], 'current_stock' => round((float) $proposal['current_stock'], 6), 'target_stock' => round($target, 6), 'forecast_quantity' => $proposal['forecast_quantity'], 'horizon_days' => $horizon, 'buckets' => $buckets];
        })->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'horizon_days' => $horizon, 'generated_at' => now()->toISOString()]]);
    }
}
