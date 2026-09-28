<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryCostLayer;
use App\Models\InventoryReconciliationSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventorySnapshotService
{
    private const INBOUND = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    /**
     * Capture a deterministic, immutable balance snapshot. Repeating the
     * same company/date returns the original record instead of rewriting
     * audit evidence.
     */
    public function capture(?int $companyId, string|Carbon $asOfDate, ?int $createdBy = null): InventoryReconciliationSnapshot
    {
        $date = Carbon::parse($asOfDate)->toDateString();
        $existing = InventoryReconciliationSnapshot::withoutGlobalScopes()
            ->where(fn ($query) => $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId))
            ->whereDate('as_of_date', $date)->first();
        if ($existing) return $existing;

        $inbound = "'".implode("','", self::INBOUND)."'";
        $outbound = "'".implode("','", self::OUTBOUND)."'";
        $groups = InventoryMovement::withoutGlobalScopes()
            ->where(fn ($query) => $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($companyId !== null, fn ($query) => $query->whereHas('product', fn ($productQuery) => $productQuery->withoutGlobalScopes()->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'))))
            ->where('posted_at', '<=', Carbon::parse($date)->endOfDay())
            ->select('product_id', 'location_id')
            ->selectRaw("SUM(CASE WHEN movement_type IN ({$inbound}) THEN quantity ELSE 0 END) AS inbound_quantity")
            ->selectRaw("SUM(CASE WHEN movement_type IN ({$outbound}) THEN quantity ELSE 0 END) AS outbound_quantity")
            ->selectRaw("SUM(CASE WHEN movement_type IN ({$inbound}) THEN quantity WHEN movement_type IN ({$outbound}) THEN -quantity ELSE 0 END) AS balance_quantity")
            ->selectRaw("SUM(CASE WHEN movement_type IN ({$inbound}) THEN quantity * COALESCE(unit_cost, 0) WHEN movement_type IN ({$outbound}) THEN -quantity * COALESCE(unit_cost, 0) ELSE 0 END) AS balance_value")
            ->selectRaw('COUNT(*) AS movement_count')
            ->groupBy('product_id', 'location_id')->orderBy('product_id')->orderBy('location_id')->get();

        $rows = $groups->map(fn ($row): array => [
            'product_id' => (int) $row->product_id,
            'location_id' => $row->location_id === null ? null : (int) $row->location_id,
            'inbound_quantity' => (float) $row->inbound_quantity,
            'outbound_quantity' => (float) $row->outbound_quantity,
            'balance_quantity' => (float) $row->balance_quantity,
            'balance_value' => (float) $row->balance_value,
            'movement_count' => (int) $row->movement_count,
        ])->values()->all();
        $cutoff = Carbon::parse($date)->endOfDay();
        $layerValues = InventoryCostLayer::withoutGlobalScopes()
            ->with(['consumptions.movement'])
            ->whereDate('received_at', '<=', $date)
            ->whereHas('product', function ($query) use ($companyId): void {
                $query->withoutGlobalScopes()->where(fn ($scope) => $companyId === null
                    ? $scope->whereNull('company_id')
                    : $scope->where('company_id', $companyId)->orWhereNull('company_id'));
            })
            ->get()
            ->groupBy(fn ($layer): string => $layer->product_id.'|'.($layer->location_id ?? 'null'))
            ->map(function ($layers) use ($cutoff): float {
                return (float) $layers->sum(function ($layer) use ($cutoff): float {
                    $consumed = $layer->consumptions->filter(function ($consumption) use ($cutoff): bool {
                        $date = $consumption->movement?->posted_at ?? $consumption->created_at;
                        return $date !== null && Carbon::parse($date)->lte($cutoff);
                    })->sum('quantity');
                    $remaining = max(0, (float) $layer->original_quantity - (float) $consumed);
                    return $remaining * (float) $layer->unit_cost;
                });
            });
        $rows = collect($rows)->map(function (array $row) use ($layerValues): array {
            $key = $row['product_id'].'|'.($row['location_id'] ?? 'null');
            if ($layerValues->has($key)) {
                $row['cost_layer_value'] = round((float) $layerValues->get($key), 6);
                $row['valuation_value'] = $row['cost_layer_value'];
                $row['valuation_source'] = 'cost_layers';
            } else {
                $row['cost_layer_value'] = null;
                $row['valuation_value'] = $row['balance_value'];
                $row['valuation_source'] = 'movement_cost';
            }
            return $row;
        })->all();
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $totalQuantity = array_sum(array_column($rows, 'balance_quantity'));
        $movementCount = array_sum(array_column($rows, 'movement_count'));
        $hasVariance = collect($rows)->contains(fn (array $row): bool => $row['balance_quantity'] < -0.000001);

        return InventoryReconciliationSnapshot::create([
            'company_id' => $companyId, 'as_of_date' => $date, 'status' => $hasVariance ? 'variance' : 'balanced',
            'movement_count' => $movementCount, 'line_count' => count($rows), 'total_quantity' => $totalQuantity,
            'rows' => $rows, 'snapshot_hash' => hash('sha256', $json), 'created_by' => $createdBy,
        ]);
    }
}
