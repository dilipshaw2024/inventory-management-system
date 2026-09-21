<?php

namespace App\Services;

use App\Models\BillOfMaterial;
use App\Models\PurchaseOrderLine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\InventoryReplenishmentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MrpPlanningService
{
    public function proposalsForCompany(int $companyId): array
    {
        $products = Product::withoutGlobalScope('company')->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->where('status', 1)->with('supplier')->get()->keyBy('id');
        $openPurchase = PurchaseOrderLine::whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))->get()->groupBy('product_id')->map(fn (Collection $lines): float => (float) $lines->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty)));
        $openProduction = ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['draft', 'released', 'in_progress'])->get()->groupBy('product_id')->map(fn (Collection $orders): float => (float) $orders->sum(fn (ProductionOrder $order): float => max(0, (float) $order->planned_quantity - (float) $order->completed_quantity)));
        $policies = InventoryReplenishmentPolicy::where('is_active', true)->whereIn('product_id', $products->pluck('id'))->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->get()->groupBy('product_id');
        $requirements = []; $errors = [];
        BillOfMaterial::withoutGlobalScopes()->with('product')->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->whereHas('product', fn ($query) => $query->withoutGlobalScope('company')->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->where('is_active', true)->where('approval_status', 'approved')->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString()))->get()->each(function (BillOfMaterial $bom) use (&$requirements, &$errors, $companyId): void {
            if (!$bom->product) return;
            $target = (float) ($bom->product->max_stock ?: max((float) $bom->product->reorder_level * 2, (float) $bom->output_quantity));
            $suggested = max(0, $target - app(InventoryAvailabilityService::class)->available($bom->product, true, null, $companyId));
            if ($suggested <= 0) return;
            try {
                foreach (app(BomExplosionService::class)->leafRequirements($bom, $suggested, $companyId) as $productId => $quantity) $requirements[$productId] = ($requirements[$productId] ?? 0) + $quantity;
            } catch (\RuntimeException $exception) { $errors[] = $bom->code.': '.$exception->getMessage(); }
        });
        $proposals = collect($requirements)->map(function (float $required, int $productId) use ($products, $companyId, $openPurchase, $openProduction, $policies): ?array {
            $product = $products->get($productId); if (!$product) return null;
            $policyRows = $policies->get($productId, collect());
            $safetyStock = (float) $policyRows->max(fn (InventoryReplenishmentPolicy $policy): float => (float) ($policy->safety_stock ?? 0));
            $leadTimeDays = (int) $policyRows->max(fn (InventoryReplenishmentPolicy $policy): int => (int) ($policy->lead_time_days ?? 0));
            $safetyTimeDays = (int) $policyRows->max(fn (InventoryReplenishmentPolicy $policy): int => (int) ($policy->safety_time_days ?? 0));
            $onHand = app(InventoryAvailabilityService::class)->available($product, true, null, $companyId); $purchaseSupply = (float) ($openPurchase[$productId] ?? 0); $productionSupply = (float) ($openProduction[$productId] ?? 0); $netAvailable = $onHand + $purchaseSupply + $productionSupply; $shortage = max(0, $required + $safetyStock - $netAvailable);
            $price = $product->supplier_id ? app(SupplierProductPriceService::class)->planningFor($product->supplier, $product, $shortage, now()->toDateString(), null) : null;
            $minimumOrderQuantity = (float) ($price?->minimum_quantity ?? 0);
            $plannedOrderQuantity = max($shortage, $minimumOrderQuantity);
            $supplierLeadTime = (int) ($price?->lead_time_days ?? 0);
            $planningDays = max($leadTimeDays, $supplierLeadTime) + $safetyTimeDays;
            $today = CarbonImmutable::today();
            return ['product' => $product, 'product_id' => $product->id, 'required' => $required, 'safety_stock' => $safetyStock, 'on_hand' => $onHand, 'open_purchase_quantity' => $purchaseSupply, 'open_production_quantity' => $productionSupply, 'net_available' => $netAvailable, 'shortage' => $shortage, 'minimum_order_quantity' => $minimumOrderQuantity, 'planned_order_quantity' => $plannedOrderQuantity, 'price' => $price, 'action' => $product->supplier_id ? 'Buy' : 'Make', 'supply_type' => $product->supplier_id ? 'purchase' : 'production', 'lead_time_days' => max($leadTimeDays, $supplierLeadTime), 'safety_time_days' => $safetyTimeDays, 'planning_days' => $planningDays, 'suggested_order_date' => $today->toDateString(), 'required_by' => $today->addDays($planningDays)->toDateString()];
        })->filter(fn (?array $row): bool => $row !== null && $row['shortage'] > 0)->sortByDesc('shortage')->values();
        return ['proposals' => $proposals, 'errors' => $errors];
    }
}
