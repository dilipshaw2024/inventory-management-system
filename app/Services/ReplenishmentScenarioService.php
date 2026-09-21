<?php

namespace App\Services;

use App\Models\PurchaseOrderLine;
use App\Models\InventoryTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReplenishmentScenarioService
{
    public function simulate(int $companyId, ?int $productId, ?int $locationId, int $horizonDays, float $demandMultiplier = 1.0, ?float $dailyDemandOverride = null): Collection
    {
        $proposals = app(ReplenishmentPlanningService::class)->proposalsForCompany($companyId, $productId, $locationId, $horizonDays);
        $today = CarbonImmutable::today();
        $productIds = $proposals->pluck('product_id')->unique()->values();
        $openReceipts = PurchaseOrderLine::with('purchaseOrder')
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
            ->get()
            ->mapToGroups(function (PurchaseOrderLine $line): array {
                $date = $line->purchaseOrder?->expected_date ?: $line->purchaseOrder?->date;
                return [$date?->toDateString() => ['product_id' => (int) $line->product_id, 'quantity' => max(0, (float) $line->ordered_qty - (float) $line->received_qty)]];
            });
        $openTransferReceipts = InventoryTransfer::withoutGlobalScopes()
            ->with('lines')
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'approved', 'in_transit', 'partially_received'])
            ->get()
            ->flatMap(function (InventoryTransfer $transfer): array {
                $date = ($transfer->expected_arrival ?: $transfer->date)?->toDateString();
                return $transfer->lines->map(fn ($line): array => [
                    'date' => $date,
                    'product_id' => (int) $line->product_id,
                    'location_id' => (int) $line->destination_location_id,
                    'quantity' => max(0, (float) $line->quantity - (float) ($line->received_quantity ?? 0)),
                ])->all();
            })
            ->groupBy(fn (array $row): string => $row['date'].'|'.$row['product_id'].'|'.$row['location_id']);
        $rows = collect();

        foreach ($proposals as $proposal) {
            $baselineDailyDemand = $proposal['forecast_quantity'] === null
                ? 0.0
                : (float) $proposal['forecast_quantity'] / max(1, $horizonDays);
            $scenarioDailyDemand = $dailyDemandOverride === null
                ? $baselineDailyDemand * $demandMultiplier
                : $dailyDemandOverride;
            $baselineBalance = (float) $proposal['current_stock'];
            $scenarioBalance = $baselineBalance;
            $buckets = collect(range(0, $horizonDays - 1))->map(function (int $offset) use (&$baselineBalance, &$scenarioBalance, $proposal, $baselineDailyDemand, $scenarioDailyDemand, $today, $openReceipts, $openTransferReceipts): array {
                $date = $today->addDays($offset)->toDateString();
                $plannedReceipt = $date === $proposal['expected_receipt_date'] ? (float) $proposal['quantity'] : 0.0;
                $openPurchaseReceipt = (float) collect($openReceipts->get($date, []))->where('product_id', (int) $proposal['product_id'])->sum('quantity');
                $transferKey = $date.'|'.$proposal['product_id'].'|'.($proposal['location_id'] ?? 0);
                $openTransferReceipt = (float) $openTransferReceipts->get($transferKey, collect())->sum('quantity');
                $baselineOpening = $baselineBalance;
                $scenarioOpening = $scenarioBalance;
                $receipts = $plannedReceipt + $openPurchaseReceipt + $openTransferReceipt;
                $baselineBalance += $receipts - $baselineDailyDemand;
                $scenarioBalance += $receipts - $scenarioDailyDemand;
                $target = (float) ($proposal['target_stock'] ?? 0);
                return [
                    'date' => $date,
                    'planned_receipt' => round($plannedReceipt, 6),
                    'open_purchase_receipt' => round($openPurchaseReceipt, 6),
                    'open_transfer_receipt' => round($openTransferReceipt, 6),
                    'baseline_demand' => round($baselineDailyDemand, 6),
                    'scenario_demand' => round($scenarioDailyDemand, 6),
                    'baseline_opening_balance' => round($baselineOpening, 6),
                    'scenario_opening_balance' => round($scenarioOpening, 6),
                    'baseline_projected_balance' => round($baselineBalance, 6),
                    'scenario_projected_balance' => round($scenarioBalance, 6),
                    'baseline_shortfall' => round(max(0, $target - $baselineBalance), 6),
                    'scenario_shortfall' => round(max(0, $target - $scenarioBalance), 6),
                    'scenario_status' => $scenarioBalance < 0 ? 'stockout' : ($scenarioBalance <= $target ? 'reorder' : 'covered'),
                ];
            })->values();
            $rows->push([
                'product_id' => (int) $proposal['product_id'],
                'location_id' => $proposal['location_id'],
                'current_stock' => round((float) $proposal['current_stock'], 6),
                'target_stock' => round((float) ($proposal['target_stock'] ?? 0), 6),
                'horizon_days' => $horizonDays,
                'demand_multiplier' => round($demandMultiplier, 6),
                'daily_demand_override' => $dailyDemandOverride === null ? null : round($dailyDemandOverride, 6),
                'baseline_daily_demand' => round($baselineDailyDemand, 6),
                'scenario_daily_demand' => round($scenarioDailyDemand, 6),
                'buckets' => $buckets,
            ]);
        }

        return $rows->values();
    }
}
