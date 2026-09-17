<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryStatusBalance;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class InventoryAvailabilityService
{
    private const LOCATION_INBOUND = ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
    private const LOCATION_OUTBOUND = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];

    /** @return array<int, float> */
    public function availableMany(iterable $products, bool $subtractReservations = true, ?int $locationId = null, ?int $companyId = null): array
    {
        $products = collect($products);
        $ids = $products->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();
        if ($ids->isEmpty()) return [];
        $movements = InventoryMovement::whereIn('product_id', $ids)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')))
            ->selectRaw('product_id, COUNT(*) AS movement_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN movement_type IN ('.implode(',', array_fill(0, count(self::LOCATION_INBOUND), '?')).') THEN quantity WHEN movement_type IN ('.implode(',', array_fill(0, count(self::LOCATION_OUTBOUND), '?')).') THEN -quantity ELSE 0 END), 0) AS balance', array_merge(self::LOCATION_INBOUND, self::LOCATION_OUTBOUND))
            ->groupBy('product_id')->get()->keyBy('product_id');
        $nonAvailable = InventoryStatusBalance::whereIn('product_id', $ids)->whereIn('status', ['blocked', 'quarantine', 'damaged'])
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')))
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $reserved = $subtractReservations ? StockReservation::whereIn('product_id', $ids)->where('status', 'active')
            ->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))
            ->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')))
            ->selectRaw('product_id, COALESCE(SUM(quantity - released_quantity), 0) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id') : collect();

        return $products->mapWithKeys(function (Product $product) use ($movements, $nonAvailable, $reserved, $subtractReservations, $locationId): array {
            $movement = $movements->get($product->id);
            $hasLedger = $movement !== null && (int) $movement->movement_count > 0;
            $onHand = $hasLedger ? (float) $movement->balance : (float) $product->quantity;
            $qualityHold = !$hasLedger && $locationId === null ? (float) ($nonAvailable[$product->id] ?? 0) : 0.0;
            $reservedQuantity = $subtractReservations ? (float) ($reserved[$product->id] ?? 0) : 0.0;
            return [$product->id => max(0, $onHand - $qualityHold - $reservedQuantity)];
        })->all();
    }

    /**
     * Return stock that can be allocated, excluding blocked quality statuses.
     * Reservations are excluded by default; fulfillment callers can opt out
     * after validating their own linked reservation.
     */
    public function available(Product $product, bool $subtractReservations = true, ?int $locationId = null, ?int $companyId = null): float
    {
        $companyId ??= $product->company_id;
        $movementQuery = InventoryMovement::where('product_id', $product->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')));
        $hasLedger = $movementQuery->exists();
        $onHand = (float) $movementQuery->selectRaw('COALESCE(SUM(CASE WHEN movement_type IN ('.implode(',', array_fill(0, count(self::LOCATION_INBOUND), '?')).') THEN quantity WHEN movement_type IN ('.implode(',', array_fill(0, count(self::LOCATION_OUTBOUND), '?')).') THEN -quantity ELSE 0 END), 0) AS balance', array_merge(self::LOCATION_INBOUND, self::LOCATION_OUTBOUND))->value('balance');
        if (!$hasLedger && $locationId === null) $onHand = (float) $product->quantity;
        // Ledger balances already include physical availability movements
        // (including quarantine/release). Subtracting status balances again
        // would double-count those quantities. Legacy products with no ledger
        // history still need the status-balance deduction.
        $nonAvailable = $locationId === null && !$hasLedger
            ? (float) InventoryStatusBalance::where('product_id', $product->id)
                ->whereIn('status', ['blocked', 'quarantine', 'damaged'])
                ->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')))
                ->sum('quantity')
            : 0.0;
        $reserved = $subtractReservations
            ? (float) StockReservation::where('product_id', $product->id)->where('status', 'active')->when($companyId !== null, fn ($query) => $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id')))->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))->sum(DB::raw('quantity - released_quantity'))
            : 0;

        return max(0, $onHand - $nonAvailable - $reserved);
    }
}
