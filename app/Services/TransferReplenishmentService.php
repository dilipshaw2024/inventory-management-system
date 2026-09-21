<?php

namespace App\Services;

use App\Models\InventoryReplenishmentPolicy;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class TransferReplenishmentService
{
    public function suggestionsForCompany(int $companyId, ?int $productId = null, ?int $destinationLocationId = null): Collection
    {
        $products = Product::withoutGlobalScope('company')
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where('status', 1)
            ->where(fn ($query) => $query->where('is_stock_item', true)->orWhereNull('is_stock_item'))
            ->when($productId, fn ($query, $id) => $query->whereKey($id))
            ->with('unit')
            ->get();
        $policies = InventoryReplenishmentPolicy::with('location.warehouse.branch')
            ->where('is_active', true)
            ->whereHas('location.warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id');
        $openTransfers = InventoryTransfer::withoutGlobalScopes()
            ->with('lines')
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'approved', 'in_transit', 'partially_received'])
            ->get();
        $openInbound = [];
        $openOutbound = [];
        foreach ($openTransfers as $transfer) {
            foreach ($transfer->lines as $line) {
                $remaining = max(0, (float) $line->quantity - (float) ($line->received_quantity ?? 0));
                if ($remaining <= 0.000001) continue;
                $key = $line->product_id.'|'.$line->destination_location_id;
                $openInbound[$key] = ($openInbound[$key] ?? 0) + $remaining;
                if (in_array($transfer->status, ['pending', 'approved'], true)) {
                    $sourceKey = $line->product_id.'|'.$line->source_location_id;
                    $openOutbound[$sourceKey] = ($openOutbound[$sourceKey] ?? 0) + $remaining;
                }
            }
        }
        $suggestions = collect();
        foreach ($products as $product) {
            $locations = $policies->get($product->id, collect())->map(function (InventoryReplenishmentPolicy $policy) use ($product, $companyId, $openInbound, $openOutbound): array {
                $key = $product->id.'|'.$policy->location_id;
                $onHand = (float) app(InventoryAvailabilityService::class)->available($product, true, (int) $policy->location_id, $companyId);
                $inbound = (float) ($openInbound[$key] ?? 0);
                $outbound = (float) ($openOutbound[$key] ?? 0);
                $current = max(0, $onHand + $inbound - $outbound);
                $safety = (float) $policy->safety_stock;
                $target = $policy->max_stock !== null ? (float) $policy->max_stock : ($policy->min_stock !== null ? (float) $policy->min_stock : (float) $policy->reorder_point + $safety);
                $floor = $policy->min_stock !== null ? (float) $policy->min_stock : (float) $policy->reorder_point;
                return [
                    'policy' => $policy,
                    'current' => $current,
                    'target' => max($target, $floor),
                    'floor' => $floor,
                    // An open outbound transfer is already committed to its
                    // destination; it reduces transferable surplus, but must
                    // not make the source look like a new shortage.
                    'shortage' => max(0, $target - $onHand - $inbound),
                    'surplus' => max(0, $onHand + $inbound - $floor - $outbound),
                ];
            })->values();
            $destinations = $locations
                ->filter(fn (array $row): bool => $row['shortage'] > 0)
                ->when($destinationLocationId !== null, fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => (int) $row['policy']->location_id === $destinationLocationId))
                ->sortByDesc('shortage')
                ->values();
            $sources = $locations->filter(fn (array $row): bool => $row['surplus'] > 0)->sortByDesc('surplus')->values()->all();
            foreach ($destinations as $destination) {
                $remaining = (float) $destination['shortage'];
                foreach ($sources as $index => $source) {
                    if ($remaining <= 0.000001 || (int) $source['policy']->location_id === (int) $destination['policy']->location_id || $source['surplus'] <= 0.000001) continue;
                    $quantity = min($remaining, (float) $source['surplus']);
                    $suggestions->push([
                        'product' => $product, 'product_id' => (int) $product->id, 'source_location_id' => (int) $source['policy']->location_id,
                        'source_location_code' => $source['policy']->location?->code, 'destination_location_id' => (int) $destination['policy']->location_id,
                        'destination_location_code' => $destination['policy']->location?->code, 'quantity' => round($quantity, 6),
                        'source_surplus' => round((float) $source['surplus'], 6), 'destination_shortage' => round($remaining, 6),
                        'reason' => 'replenishment_shortage',
                    ]);
                    $remaining -= $quantity; $sources[$index]['surplus'] -= $quantity;
                }
            }
        }
        return $suggestions->values();
    }

    public function createPendingTransfer(array $suggestion, int $companyId, ?int $createdBy = null, ?string $externalReference = null, ?string $expectedArrival = null, ?string $description = null): InventoryTransfer
    {
        return DB::transaction(function () use ($suggestion, $companyId, $createdBy, $externalReference, $expectedArrival, $description): InventoryTransfer {
            if ($externalReference) {
                $existing = InventoryTransfer::where('company_id', $companyId)
                    ->where('external_reference', $externalReference)
                    ->lockForUpdate()->first();
                if ($existing) return $existing;
            }

            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'external_reference' => $externalReference,
                'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_transfer', 'TRF-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId),
                'date' => now()->toDateString(),
                'expected_arrival' => $expectedArrival,
                'description' => $description ?: 'Replenishment transfer suggestion.',
                'status' => 'pending',
                'created_by' => $createdBy,
            ]);
            InventoryTransferLine::create([
                'transfer_id' => $transfer->id,
                'product_id' => $suggestion['product_id'],
                'source_location_id' => $suggestion['source_location_id'],
                'destination_location_id' => $suggestion['destination_location_id'],
                'quantity' => $suggestion['quantity'],
                'unit_cost' => $suggestion['product']->purchase_price ?? null,
            ]);
            app(AuditService::class)->record('inventory_transfer.replenishment_suggestion_created', $transfer, null, $transfer->toArray() + ['approval_required' => true, 'scheduled' => $createdBy === null]);
            return $transfer;
        });
    }
}
