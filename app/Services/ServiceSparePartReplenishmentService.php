<?php

namespace App\Services;

use App\Models\AssetSparePart;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceSparePartReplenishmentService
{
    public function recommendations(int $companyId, ?int $assetId = null, ?int $productId = null): Collection
    {
        $parts = AssetSparePart::with(['asset:id,asset_no,name', 'product:id,name,sku,quantity,purchase_price', 'supplier:id,name'])
            ->where('company_id', $companyId)->where('minimum_stock', '>', 0)
            ->when($assetId, fn ($query) => $query->where('asset_id', $assetId))
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->orderBy('product_id')->orderBy('id')->get();
        $availability = app(InventoryAvailabilityService::class)->availableMany($parts->pluck('product'), true, null, $companyId);
        return $parts->map(function (AssetSparePart $part) use ($availability): ?array {
            $available = (float) ($availability[$part->product_id] ?? 0);
            $minimum = (float) $part->minimum_stock;
            if ($available >= $minimum - 0.000001) return null;
            $target = $part->maximum_stock !== null ? max($minimum, (float) $part->maximum_stock) : $minimum;
            $suggested = max(0, $target - $available);
            return [
                'asset_spare_part_id' => $part->id, 'asset' => $part->asset, 'product' => $part->product,
                'supplier' => $part->supplier, 'available_quantity' => round($available, 6),
                'minimum_stock' => round($minimum, 6), 'target_stock' => round($target, 6),
                'suggested_quantity' => round($suggested, 6), 'lead_time_days' => $part->lead_time_days,
                'estimated_cost' => round($suggested * (float) ($part->supplier_unit_cost ?? $part->product?->purchase_price ?? 0), 6),
                'currency' => $part->supplier_currency,
            ];
        })->filter()->values();
    }

    /** @return array{orders: Collection, existing_only: bool} */
    public function createPurchaseOrders(int $companyId, array $items, string $externalReference, string $date, ?string $expectedDate = null, ?int $userId = null, ?int $branchId = null): array
    {
        $parts = AssetSparePart::with(['product', 'supplier'])
            ->where('company_id', $companyId)->whereIn('id', collect($items)->pluck('asset_spare_part_id'))->get()->keyBy('id');
        if ($parts->count() !== count(collect($items)->pluck('asset_spare_part_id')->unique())) throw new \RuntimeException('Every spare-part reference must belong to the current company.');
        $availability = app(InventoryAvailabilityService::class)->availableMany($parts->pluck('product'), true, null, $companyId);
        $groups = collect($items)->map(function (array $item) use ($parts, $availability): array {
            $part = $parts->get((int) $item['asset_spare_part_id']);
            if (!$part || !$part->supplier_id || !$part->supplier || !$part->supplier->is_active) throw new \RuntimeException('Every selected spare part must have an active supplier.');
            app(ProductLifecycleService::class)->assertPurchasable($part->product);
            $available = (float) ($availability[$part->product_id] ?? 0);
            $minimum = (float) $part->minimum_stock;
            $target = $part->maximum_stock !== null ? max($minimum, (float) $part->maximum_stock) : $minimum;
            $suggested = max(0, $target - $available);
            $quantity = array_key_exists('quantity', $item) && $item['quantity'] !== null ? (float) $item['quantity'] : $suggested;
            if ($available >= $minimum - 0.000001 || $quantity <= 0 || $quantity > $suggested + 0.000001) throw new \RuntimeException('The selected spare-part replenishment is no longer valid for the current stock position.');
            $currency = strtoupper((string) ($part->supplier_currency ?: 'USD'));
            return ['supplier_id' => (int) $part->supplier_id, 'currency' => $currency, 'product_id' => (int) $part->product_id, 'quantity' => $quantity, 'unit_price' => (float) ($part->supplier_unit_cost ?? $part->product->purchase_price ?? 0)];
        })->groupBy(fn (array $item): string => $item['supplier_id'].'|'.$item['currency']);
        $orders = DB::transaction(function () use ($groups, $companyId, $externalReference, $date, $expectedDate, $userId, $branchId): Collection {
            $created = collect();
            foreach ($groups as $groupKey => $groupItems) {
                [$supplierId, $currency] = explode('|', $groupKey, 2);
                $reference = count($groups) === 1 ? $externalReference : substr($externalReference.'-'.$supplierId.'-'.$currency, 0, 150);
                $existing = PurchaseOrder::where('company_id', $companyId)->where('external_reference', $reference)->first();
                if ($existing) { $created->push($existing->load('supplier', 'lines.product')); continue; }
                $order = PurchaseOrder::create([
                    'company_id' => $companyId, 'external_reference' => $reference, 'supplier_id' => (int) $supplierId,
                    'date' => $date, 'expected_date' => $expectedDate, 'description' => 'Service spare-part replenishment '.$externalReference,
                    'currency_code' => $currency, 'exchange_rate' => 1,
                    'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $branchId),
                    'status' => 'submitted', 'created_by' => $userId,
                ]);
                foreach ($groupItems->groupBy(fn (array $item): string => $item['product_id'].'|'.number_format((float) $item['unit_price'], 6, '.', '')) as $productItems) {
                    $line = $productItems->first();
                    PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line['product_id'], 'ordered_qty' => (float) $productItems->sum('quantity'), 'unit_price' => (float) $line['unit_price']]);
                }
                app(AuditService::class)->record('service_spare_part_replenishment.purchase_order_created', $order, null, $order->toArray() + ['source_external_reference' => $externalReference]);
                $created->push($order->load('supplier', 'lines.product'));
            }
            return $created;
        });
        return ['orders' => $orders, 'existing_only' => $orders->every(fn (PurchaseOrder $order): bool => str_starts_with((string) $order->description, 'Service spare-part replenishment') && !$order->wasRecentlyCreated)];
    }
}
