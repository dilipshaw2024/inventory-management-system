<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    private const INBOUND = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    /**
     * Compare the legacy product quantity with its immutable ledger balance.
     * This method is intentionally read-only and is safe to run repeatedly.
     */
    public function rows(?int $companyId = null, float $tolerance = 0.000001): Collection
    {
        $ledger = InventoryMovement::query()
            ->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->selectRaw("product_id, COUNT(*) AS movement_count, COALESCE(SUM(CASE WHEN movement_type IN ('".implode("','", self::INBOUND)."') THEN quantity WHEN movement_type IN ('".implode("','", self::OUTBOUND)."') THEN -quantity ELSE 0 END), 0) AS ledger_quantity")
            ->groupBy('product_id')
            ->get()->keyBy('product_id');

        return Product::query()
            ->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->orderBy('id')
            ->get(['id', 'company_id', 'name', 'sku', 'quantity'])
            ->map(function (Product $product) use ($ledger, $tolerance): array {
                $legacy = (float) $product->quantity;
                $ledgerRow = $ledger->get($product->id);
                $ledgerQuantity = (float) ($ledgerRow?->ledger_quantity ?? 0);
                $difference = $legacy - $ledgerQuantity;

                return [
                    'product' => $product,
                    'legacy_quantity' => $legacy,
                    'ledger_quantity' => $ledgerQuantity,
                    'has_ledger_history' => (int) ($ledgerRow?->movement_count ?? 0) > 0,
                    'difference' => $difference,
                    'is_reconciled' => abs($difference) <= $tolerance,
                ];
            })
            ->filter(fn (array $row): bool => !$row['is_reconciled'])
            ->values();
    }

    public function synchronizeLegacyBalances(?int $companyId, float $tolerance, string $reason): int
    {
        return DB::transaction(function () use ($companyId, $tolerance, $reason): int {
            $updated = 0;
            foreach ($this->rows($companyId, $tolerance) as $row) {
                if (!$row['has_ledger_history']) continue;
                $product = Product::withoutGlobalScopes()->lockForUpdate()->find($row['product']->id);
                if (!$product || abs((float) $product->quantity - (float) $row['ledger_quantity']) <= $tolerance) continue;
                $before = ['quantity' => (float) $product->quantity];
                $product->update(['quantity' => (float) $row['ledger_quantity']]);
                app(AuditService::class)->record('inventory.legacy_balance.synchronized', $product, $before, ['quantity' => (float) $product->quantity, 'reason' => $reason, 'ledger_quantity' => (float) $row['ledger_quantity']]);
                $updated++;
            }
            return $updated;
        });
    }
}
