<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Collection;

class PlanningActionQueueService
{
    public function forCompany(int $companyId): Collection
    {
        $replenishment = app(ReplenishmentPlanningService::class)->proposalsForCompany($companyId);
        $production = app(ProductionSuggestionService::class)->forCompany($companyId);
        $expiryDays = (int) app(ErpSettingService::class)->get('expiry_alert_days', 90, $companyId);
        $expiring = InventoryBatch::whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($expiryDays))
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->count();
        $products = Product::where('status', 1)->get();
        $availability = app(InventoryAvailabilityService::class)->availableMany($products, true, null, $companyId);
        $excess = $products->filter(fn (Product $product): bool => $product->max_stock !== null && (float) ($availability[$product->id] ?? 0) > (float) $product->max_stock)->count();
        $deadDays = (int) app(ErpSettingService::class)->get('dead_stock_days', 180, $companyId);
        $recentIssues = InventoryMovement::whereIn('product_id', $products->pluck('id'))
            ->where('movement_type', 'issue')->where('posted_at', '>=', now()->subDays($deadDays))
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->pluck('product_id')->unique();
        $dead = $products->filter(fn (Product $product): bool => (float) ($availability[$product->id] ?? 0) > 0 && !$recentIssues->contains($product->id))->count();

        return collect([
            [
                'action_type' => 'replenishment',
                'priority' => $replenishment->isNotEmpty() ? 'high' : 'normal',
                'count' => $replenishment->count(),
                'requires_approval' => true,
                'action' => 'Create approval-pending purchase orders from replenishment proposals.',
                'endpoint' => '/api/inventory/replenishment/auto-purchase-orders',
                'browser_route' => 'planning.purchase.suggestions',
            ],
            [
                'action_type' => 'production',
                'priority' => $production->isNotEmpty() ? 'high' : 'normal',
                'count' => $production->count(),
                'requires_approval' => true,
                'action' => 'Review production suggestions and create approval-pending production orders.',
                'endpoint' => '/api/manufacturing/orders',
                'browser_route' => 'planning.production.suggestions',
            ],
            [
                'action_type' => 'expiry',
                'priority' => $expiring > 0 ? 'high' : 'normal',
                'count' => $expiring,
                'requires_approval' => false,
                'action' => 'Review batches approaching or past expiry.',
                'endpoint' => '/api/inventory/batches',
                'browser_route' => 'stock.expiry',
            ],
            [
                'action_type' => 'excess_stock',
                'priority' => 'normal',
                'count' => $excess,
                'requires_approval' => false,
                'action' => 'Review stock above configured maximum levels.',
                'endpoint' => '/api/inventory/planning-exceptions?type=excess',
                'browser_route' => 'planning.report',
                'browser_query' => ['type' => 'excess'],
            ],
            [
                'action_type' => 'dead_stock',
                'priority' => 'normal',
                'count' => $dead,
                'requires_approval' => false,
                'action' => 'Review positive stock without a recent issue movement.',
                'endpoint' => '/api/inventory/planning-exceptions?type=dead',
                'browser_route' => 'planning.report',
                'browser_query' => ['type' => 'dead'],
            ],
        ])->values();
    }
}
