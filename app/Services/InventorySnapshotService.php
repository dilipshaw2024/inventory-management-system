<?php

namespace App\Services;

use App\Models\InventoryMovement;
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
