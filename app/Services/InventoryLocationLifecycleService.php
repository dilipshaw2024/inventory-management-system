<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryStatusBalance;

class InventoryLocationLifecycleService
{
    private const INBOUND = ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    public function deactivationBlocker(InventoryLocation $location): ?string
    {
        if ($location->children()->where('is_active', true)->exists()) {
            return 'Move or deactivate child locations before deactivating this location.';
        }

        $inbound = "'".implode("','", self::INBOUND)."'";
        $outbound = "'".implode("','", self::OUTBOUND)."'";
        $hasLedgerStock = InventoryMovement::where('location_id', $location->id)
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN movement_type IN ($inbound) THEN quantity WHEN movement_type IN ($outbound) THEN -quantity ELSE 0 END) AS balance")
            ->groupBy('product_id')
            ->havingRaw('balance > 0')
            ->exists();

        if ($hasLedgerStock || InventoryStatusBalance::where('location_id', $location->id)->where('quantity', '>', 0)->exists()) {
            return 'Move or issue all stock before deactivating this location.';
        }

        return null;
    }
}
