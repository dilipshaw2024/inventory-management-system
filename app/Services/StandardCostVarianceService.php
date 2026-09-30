<?php

namespace App\Services;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryMovement;
use Carbon\CarbonImmutable;

class StandardCostVarianceService
{
    public function report(int $companyId, ?string $from = null, ?string $to = null, ?int $productId = null, ?int $locationId = null): array
    {
        $inbound = ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in'];
        $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap'];
        $movements = InventoryMovement::withoutGlobalScopes()
            ->with(['product:id,company_id,name,sku,costing_method,standard_cost', 'location:id,code,name'])
            ->where('company_id', $companyId)
            ->whereIn('movement_type', array_merge($inbound, $outbound))
            ->when($from, fn ($query) => $query->whereDate('posted_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('posted_at', '<=', $to))
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('posted_at')->orderBy('id')->get();

        $consumptionTotals = InventoryCostConsumption::withoutGlobalScopes()
            ->whereIn('movement_id', $movements->pluck('id'))
            ->selectRaw('movement_id, SUM(total_cost) as actual_cost')
            ->groupBy('movement_id')
            ->pluck('actual_cost', 'movement_id');

        $rows = $movements->map(function (InventoryMovement $movement) use ($inbound, $consumptionTotals): ?array {
            $product = $movement->product;
            if (!$product) return null;
            $at = $movement->posted_at ?: $movement->created_at;
            $policy = app(ProductCostingPolicyService::class)->resolve($product, $at ? CarbonImmutable::parse((string) $at) : null);
            if ($policy['costing_method'] !== 'standard' || $policy['standard_cost'] === null) return null;

            $quantity = (float) $movement->quantity;
            $standardUnitCost = (float) $policy['standard_cost'];
            $isInbound = in_array($movement->movement_type, $inbound, true);
            $actualTotal = !$isInbound && $consumptionTotals->has($movement->id)
                ? (float) $consumptionTotals->get($movement->id)
                : $quantity * (float) ($movement->unit_cost ?? 0);
            $standardTotal = $quantity * $standardUnitCost;

            return [
                'movement_id' => (int) $movement->id,
                'posted_at' => optional($movement->posted_at)->toISOString(),
                'movement_type' => $movement->movement_type,
                'direction' => $isInbound ? 'inbound' : 'outbound',
                'product_id' => (int) $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'location_id' => $movement->location_id,
                'location_code' => $movement->location?->code,
                'quantity' => round($quantity, 6),
                'actual_unit_cost' => round($quantity > 0 ? $actualTotal / $quantity : 0, 6),
                'standard_unit_cost' => round($standardUnitCost, 6),
                'actual_value' => round($actualTotal, 6),
                'standard_value' => round($standardTotal, 6),
                'variance_amount' => round($actualTotal - $standardTotal, 6),
                'variance_status' => abs($actualTotal - $standardTotal) <= 0.000001 ? 'within_standard' : ($actualTotal > $standardTotal ? 'above_standard' : 'below_standard'),
                'source_type' => $movement->reference_type,
                'source_id' => $movement->reference_id,
                'policy_id' => $policy['policy_id'],
            ];
        })->filter()->values();

        return [
            'data' => $rows,
            'summary' => [
                'movement_count' => $rows->count(),
                'inbound_count' => $rows->where('direction', 'inbound')->count(),
                'outbound_count' => $rows->where('direction', 'outbound')->count(),
                'actual_value' => round((float) $rows->sum('actual_value'), 6),
                'standard_value' => round((float) $rows->sum('standard_value'), 6),
                'variance_amount' => round((float) $rows->sum('variance_amount'), 6),
                'above_standard_count' => $rows->where('variance_status', 'above_standard')->count(),
                'below_standard_count' => $rows->where('variance_status', 'below_standard')->count(),
            ],
        ];
    }
}
