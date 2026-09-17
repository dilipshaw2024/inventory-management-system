<?php

namespace App\Services;

use App\Models\Product;
use App\Models\InventoryMovement;

class ProductLifecycleService
{
    public function assertPurchasable(Product $product): void
    {
        if (!$this->isAvailable($product) || !$product->can_purchase) {
            throw new \RuntimeException('Product '.$product->name.' is not available for purchasing.');
        }
    }

    public function assertSellable(Product $product): void
    {
        if (!$this->isAvailable($product) || !$product->can_sell) {
            throw new \RuntimeException('Product '.$product->name.' is not available for sales.');
        }
    }

    public function assertStockManaged(Product $product): void
    {
        if (!$this->isAvailable($product) || !$product->is_stock_item) {
            throw new \RuntimeException('Product '.$product->name.' is not an active stock-managed item.');
        }
    }

    public function isAvailable(Product $product): bool
    {
        return (int) $product->status === 1 && in_array($product->lifecycle_status ?: 'active', ['active'], true);
    }

    public function assertTransitionAllowed(Product $product, array $attributes): void
    {
        $stockItem = array_key_exists('is_stock_item', $attributes)
            ? (bool) filter_var($attributes['is_stock_item'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($product->is_stock_item ?? true);
        $lifecycle = $attributes['lifecycle_status'] ?? ($product->lifecycle_status ?: 'active');
        $hasStock = $this->hasStock($product);
        if (!$stockItem && $hasStock) throw new \RuntimeException('A product with stock cannot be changed to non-stock.');
        if ($lifecycle === 'archived' && $hasStock) throw new \RuntimeException('A product with stock cannot be archived.');
    }

    public function hasStock(Product $product): bool
    {
        if (!$product->getKey()) return (float) $product->quantity > 0.000001;
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $movementQuery = InventoryMovement::withoutGlobalScopes()->where('product_id', $product->getKey())->where(fn ($query) => $query->where('company_id', $product->company_id)->orWhereNull('company_id'));
        if ($movementQuery->exists()) {
            $balance = (float) $movementQuery->selectRaw('COALESCE(SUM(CASE WHEN movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN quantity WHEN movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -quantity ELSE 0 END), 0) AS balance', array_merge($in, $out))->value('balance');
            $held = (float) \App\Models\InventoryStatusBalance::withoutGlobalScopes()->where('product_id', $product->getKey())->whereIn('status', ['blocked', 'quarantine', 'damaged'])->where(fn ($query) => $query->where('company_id', $product->company_id)->orWhereNull('company_id'))->sum('quantity');
            return max(0, $balance + $held) > 0.000001;
        }
        return (float) $product->quantity > 0.000001;
    }
}
