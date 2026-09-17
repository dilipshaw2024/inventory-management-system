<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use App\Models\PriceListItem;

class SupplierProductPriceService
{
    public function bestFor(Supplier $supplier, Product $product, float $quantity, string $date, ?string $currencyCode = null): ?\Illuminate\Database\Eloquent\Model
    {
        $agreement = SupplierProductPrice::query()
            ->where('supplier_id', $supplier->id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->where('minimum_quantity', '<=', $quantity)
            ->when($currencyCode, fn ($query) => $query->where('currency_code', strtoupper($currencyCode)))
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderByDesc('minimum_quantity')
            ->first();
        if ($agreement) return $agreement;
        $listId = $supplier->purchase_price_list_id;
        if (!$listId) return null;
        return PriceListItem::query()->where('product_id', $product->id)->where('price_list_id', $listId)->where('is_active', true)->where('minimum_quantity', '<=', $quantity)
            ->whereHas('priceList', fn ($query) => $query->where('list_type', 'purchase')->where('is_active', true)->when($currencyCode, fn ($scope) => $scope->where('currency_code', strtoupper($currencyCode)))->where(fn ($scope) => $scope->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))->where(fn ($scope) => $scope->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date)))
            ->orderByDesc('minimum_quantity')->first();
    }
}
