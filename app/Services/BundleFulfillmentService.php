<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesOrderLine;
use App\Models\Delivery;

class BundleFulfillmentService
{
    /** @return array<int, float> */
    public function requirements(Product $bundle, float $bundleQuantity): array
    {
        if ($bundleQuantity <= 0) return [];
        if (($bundle->product_type ?: 'stock') !== 'bundle') return [$bundle->id => $bundleQuantity];
        $result = [];
        $this->flatten($bundle, $bundleQuantity, [], $result);
        if (!$result) throw new \RuntimeException('Bundle '.$bundle->name.' has no components.');
        return $result;
    }

    public function issue(Product $bundle, float $bundleQuantity, Delivery $delivery, SalesOrderLine $orderLine, float $bundleUnitPrice): array
    {
        $requirements = $this->requirements($bundle, $bundleQuantity);
        $componentPrices = [];
        $priceTotal = 0.0;
        foreach ($requirements as $productId => $quantity) {
            $component = Product::withoutGlobalScopes()->findOrFail($productId);
            $price = (float) ($component->sales_price ?? $component->selling_price ?? 0);
            $componentPrices[$productId] = $price;
            $priceTotal += $price * $quantity;
            if (app(InventoryAvailabilityService::class)->available($component, false, $delivery->location_id, $delivery->company_id) < $quantity) throw new \RuntimeException('Insufficient available stock for bundle component '.$component->name.'.');
        }
        $issued = [];
        foreach ($requirements as $productId => $quantity) {
            $component = Product::lockForUpdate()->findOrFail($productId);
            $allocations = app(StockReservationService::class)->batchAllocations($orderLine->id, $quantity, null, $delivery->location_id, $productId);
            $unitPrice = $priceTotal > 0 ? $bundleUnitPrice * (($componentPrices[$productId] * $quantity) / $priceTotal) / $quantity : $bundleUnitPrice;
            $serials = collect();
            if ($component->tracking_type === 'serial') foreach ($allocations as $allocation) $serials = $serials->merge(app(SerialLifecycleService::class)->issue($component, (float) $allocation['quantity'], $delivery->location_id, $allocation['batch_id']));
            $component->quantity = (float) $component->quantity - $quantity;
            $component->save();
            app(StockReservationService::class)->releaseForSalesOrderLine($orderLine->id, $quantity, $productId);
            if ($serials->isNotEmpty()) foreach ($serials as $serial) app(InventoryLedgerService::class)->post($component->id, 'issue', 1, $unitPrice, $delivery->location_id, $delivery, 'Approved bundle sales delivery', null, $serial->batch_id, $serial->id);
            else foreach ($allocations as $allocation) app(InventoryLedgerService::class)->post($component->id, 'issue', (float) $allocation['quantity'], $unitPrice, $delivery->location_id, $delivery, 'Approved bundle sales delivery', null, $allocation['batch_id']);
            $issued[] = ['product_id' => $component->id, 'quantity' => $quantity];
        }
        return $issued;
    }

    /** @return array<int, array{product_id:int, quantity:float, unit_cost:float, unit_price:?float, tax_rate:?float}> */
    public function expandReturnLine(Product $product, float $quantity, float $unitCost = 0, ?float $unitPrice = null, ?float $taxRate = null, array $serialNumbersByProduct = []): array
    {
        if (($product->product_type ?: 'stock') !== 'bundle') return [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'tax_rate' => $taxRate, 'serial_numbers' => $serialNumbersByProduct[$product->id] ?? null]];
        $requirements = $this->requirements($product, $quantity);
        $prices = [];
        $total = 0.0;
        foreach ($requirements as $productId => $required) {
            $component = Product::withoutGlobalScopes()->findOrFail($productId);
            $prices[$productId] = (float) ($component->sales_price ?? $component->selling_price ?? 0);
            $total += $prices[$productId] * $required;
        }
        return collect($requirements)->map(function (float $required, int $productId) use ($prices, $total, $unitPrice, $unitCost, $taxRate): array {
            $componentCost = (float) (Product::withoutGlobalScopes()->findOrFail($productId)->purchase_price ?? $unitCost);
            $allocatedPrice = $unitPrice === null ? null : ($total > 0 ? $unitPrice * (($prices[$productId] * $required) / $total) / $required : $unitPrice);
            $serials = $serialNumbersByProduct[$productId] ?? $serialNumbersByProduct[(string) $productId] ?? null;
            return ['product_id' => $productId, 'quantity' => $required, 'unit_cost' => $componentCost, 'unit_price' => $allocatedPrice, 'tax_rate' => $taxRate, 'serial_numbers' => is_array($serials) ? implode(',', $serials) : $serials];
        })->values()->all();
    }

    private function flatten(Product $product, float $multiplier, array $path, array &$result): void
    {
        if (isset($path[$product->id])) throw new \RuntimeException('Bundle components cannot create a circular bundle reference.');
        $components = $product->bundleComponents()->get();
        if (($product->product_type ?: 'stock') !== 'bundle' || $components->isEmpty()) {
            $result[$product->id] = ($result[$product->id] ?? 0) + $multiplier;
            return;
        }
        $path[$product->id] = true;
        foreach ($components as $component) {
            $child = Product::withoutGlobalScopes()->findOrFail($component->component_product_id);
            $this->flatten($child, $multiplier * (float) $component->quantity, $path, $result);
        }
    }
}
