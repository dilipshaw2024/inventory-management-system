<?php

namespace App\Services;

use App\Models\BillOfMaterial;
use App\Models\PurchaseOrderLine;
use App\Models\ProductionOrder;
use Illuminate\Support\Collection;

class ProductionSuggestionService
{
    public function forCompany(int $companyId, ?int $bomId = null): Collection
    {
        $openPurchaseByProduct = PurchaseOrderLine::whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))->get()->groupBy('product_id')->map(fn (Collection $lines): float => (float) $lines->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty)));
        $openProductionByProduct = ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['draft', 'released', 'in_progress'])->get()->groupBy('product_id')->map(fn (Collection $orders): float => (float) $orders->sum(fn (ProductionOrder $order): float => max(0, (float) $order->planned_quantity - (float) $order->completed_quantity)));
        return BillOfMaterial::withoutGlobalScopes()
            ->with(['product', 'lines.component'])
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereHas('product', fn ($query) => $query->withoutGlobalScope('company')->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString()))
            ->when($bomId, fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->map(function (BillOfMaterial $bom) use ($companyId, $openPurchaseByProduct, $openProductionByProduct): ?array {
                if (!$bom->product) return null;
                $target = $bom->product->max_stock !== null
                    ? (float) $bom->product->max_stock
                    : max((float) $bom->product->reorder_level * 2, (float) $bom->output_quantity);
                $available = app(InventoryAvailabilityService::class)->available($bom->product, true, null, $companyId);
                $openPurchase = (float) ($openPurchaseByProduct[$bom->product_id] ?? 0);
                $openProduction = (float) ($openProductionByProduct[$bom->product_id] ?? 0);
                $netAvailable = $available + $openPurchase + $openProduction;
                $suggested = max(0, $target - $netAvailable);
                if ($suggested <= 0.000001) return null;

                $maxBuild = $suggested;
                $shortages = [];
                try {
                    $requirements = app(BomExplosionService::class)->leafRequirements($bom, $suggested, $companyId);
                    foreach ($requirements as $productId => $required) {
                        $component = \App\Models\Product::withoutGlobalScope('company')->whereKey($productId)->first();
                        if (!$component) continue;
                        $componentAvailable = app(InventoryAvailabilityService::class)->available($component, true, null, $companyId);
                        $openPurchase = (float) ($openPurchaseByProduct[$component->id] ?? 0);
                        $openProduction = (float) ($openProductionByProduct[$component->id] ?? 0);
                        $netComponentAvailable = $componentAvailable + $openPurchase + $openProduction;
                        $maxBuild = min($maxBuild, $netComponentAvailable / max($required / $suggested, 0.000001));
                        if ($netComponentAvailable + 0.000001 < $required) $shortages[] = $component->name.' (need '.number_format($required - $netComponentAvailable, 3).')';
                    }
                } catch (\RuntimeException $exception) {
                    $shortages[] = $exception->getMessage();
                    $maxBuild = 0.0;
                }

                return ['bom' => $bom, 'target' => $target, 'available' => (float) $available, 'open_purchase_quantity' => $openPurchase, 'open_quantity' => $openProduction, 'net_available' => $netAvailable, 'suggested' => (float) $suggested, 'max_build' => max(0, (float) $maxBuild), 'shortages' => $shortages];
            })
            ->filter()
            ->values();
    }
}
