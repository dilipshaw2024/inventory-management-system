<?php

namespace App\Services;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\Product;
use Illuminate\Support\Collection;

class ProductionVarianceService
{
    public function report(int $companyId, ?string $from = null, ?string $to = null, ?string $status = null, ?int $productId = null, ?int $orderId = null): Collection
    {
        $orders = ProductionOrder::with(['product:id,name,sku,purchase_price', 'bom', 'operations.workCenter', 'operations.routingOperation'])
            ->where('company_id', $companyId)
            ->when($from, fn ($query) => $query->whereDate('planned_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('planned_date', '<=', $to))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($orderId, fn ($query) => $query->whereKey($orderId))
            ->orderBy('planned_date')->orderBy('id')->get();
        if ($orders->isEmpty()) return collect();

        $movements = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', (new ProductionOrder())->getMorphClass())
            ->whereIn('reference_id', $orders->pluck('id'))
            ->where('movement_type', 'issue')->get();
        $consumption = InventoryCostConsumption::whereIn('movement_id', $movements->pluck('id'))
            ->selectRaw('movement_id, SUM(total_cost) AS total_cost')->groupBy('movement_id')->pluck('total_cost', 'movement_id');
        $actual = $movements->groupBy('reference_id')->map(function (Collection $rows) use ($consumption): Collection {
            return $rows->groupBy('product_id')->map(function (Collection $productRows) use ($consumption): array {
                return ['quantity' => (float) $productRows->sum('quantity'), 'cost' => (float) $productRows->sum(fn ($movement): float => $consumption->has($movement->id) ? (float) $consumption->get($movement->id) : (float) $movement->quantity * (float) ($movement->unit_cost ?? 0))];
            });
        });
        $productIds = $orders->flatMap(fn (ProductionOrder $order): array => array_keys($this->requirements($order)))->merge($movements->pluck('product_id'))->unique()->values();
        $products = Product::whereIn('id', $productIds)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->keyBy('id');

        return $orders->map(function (ProductionOrder $order) use ($actual, $products): array {
            $planned = (float) $order->planned_quantity;
            $completed = (float) $order->completed_quantity;
            $completionRatio = $planned > 0 ? min(1, max(0, $completed / $planned)) : 0;
            $plannedRequirements = $this->requirements($order);
            $actualProducts = $actual->get($order->id, collect());
            $componentIds = collect(array_keys($plannedRequirements))->merge($actualProducts->keys())->unique()->values();
            $components = $componentIds->map(function ($componentId) use ($plannedRequirements, $actualProducts, $products, $completionRatio): array {
                $product = $products->get((int) $componentId);
                $plannedQuantity = (float) ($plannedRequirements[$componentId] ?? 0);
                $expectedQuantity = $plannedQuantity * $completionRatio;
                $actualQuantity = (float) ($actualProducts->get($componentId)['quantity'] ?? 0);
                $unitCost = (float) ($product?->purchase_price ?? 0);
                $actualCost = (float) ($actualProducts->get($componentId)['cost'] ?? 0);
                return ['product' => $product, 'product_id' => (int) $componentId, 'planned_quantity' => $plannedQuantity, 'expected_to_date_quantity' => $expectedQuantity, 'actual_quantity' => $actualQuantity, 'quantity_variance' => $actualQuantity - $expectedQuantity, 'planned_unit_cost' => $unitCost, 'expected_to_date_cost' => $expectedQuantity * $unitCost, 'actual_cost' => $actualCost, 'cost_variance' => $actualCost - ($expectedQuantity * $unitCost)];
            })->values();
            $plannedCost = (float) $components->sum(fn (array $row): float => $row['planned_quantity'] * $row['planned_unit_cost']);
            $expectedCost = (float) $components->sum('expected_to_date_cost');
            $actualCost = (float) $components->sum('actual_cost');
            $plannedOperationCost = 0.0; $expectedOperationCost = 0.0; $actualOperationCost = 0.0;
            foreach ($order->operations as $operation) {
                $routing = $operation->routingOperation;
                $rate = (float) ($operation->workCenter?->labor_rate ?? 0) + (float) ($operation->workCenter?->machine_rate ?? 0);
                $setup = (float) ($routing?->setup_minutes ?? 0);
                $run = (float) ($routing?->run_minutes ?? 0);
                $plannedOperationCost += (($setup + ($run * (float) $operation->planned_quantity)) / 60) * $rate;
                $expectedOperationCost += (($setup + ($run * min((float) $operation->planned_quantity, (float) $operation->planned_quantity * $completionRatio))) / 60) * $rate;
                $actualSetup = $operation->actual_setup_minutes !== null ? (float) $operation->actual_setup_minutes : $setup;
                $actualRun = $operation->actual_run_minutes !== null ? (float) $operation->actual_run_minutes : ($run * (float) $operation->completed_quantity);
                $actualOperationCost += (($actualSetup + $actualRun) / 60) * $rate;
            }
            $totalExpectedCost = $expectedCost + $expectedOperationCost;
            $totalActualCost = $actualCost + $actualOperationCost;
            return ['id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status, 'planned_date' => optional($order->planned_date)->toDateString(), 'product' => $order->product, 'planned_quantity' => $planned, 'completed_quantity' => $completed, 'yield_variance' => $completed - $planned, 'completion_percent' => $planned > 0 ? min(100, ($completed / $planned) * 100) : 0, 'planned_material_cost' => $plannedCost, 'expected_material_cost_to_date' => $expectedCost, 'actual_material_cost' => $actualCost, 'material_cost_variance' => $actualCost - $expectedCost, 'planned_operation_cost' => $plannedOperationCost, 'expected_operation_cost_to_date' => $expectedOperationCost, 'actual_operation_cost' => $actualOperationCost, 'operation_cost_variance' => $actualOperationCost - $expectedOperationCost, 'expected_total_cost_to_date' => $totalExpectedCost, 'actual_total_cost' => $totalActualCost, 'total_cost_variance' => $totalActualCost - $totalExpectedCost, 'components' => $components];
        });
    }

    private function requirements(ProductionOrder $order): array
    {
        if (!is_array($order->bom_snapshot) || empty($order->bom_snapshot)) return [];
        return app(BomExplosionService::class)->leafRequirementsFromSnapshot($order->bom_snapshot, (float) $order->planned_quantity);
    }
}
