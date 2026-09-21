<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\PriceListItem;

class CustomerProductPriceService
{
    public function bestFor(Product $product, ?Customer $customer, float $quantity, string $date, ?string $currencyCode = null, ?int $priceListId = null): ?\Illuminate\Database\Eloquent\Model
    {
        $base = fn ($query) => $query->where('product_id', $product->id)->where('is_active', true)->where('minimum_quantity', '<=', $quantity)->when($currencyCode, fn ($scope) => $scope->where('currency_code', strtoupper($currencyCode)))->where(fn ($scope) => $scope->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))->where(fn ($scope) => $scope->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
        if ($priceListId !== null) {
            $selected = PriceListItem::query()->where('product_id', $product->id)->where('price_list_id', $priceListId)->where('is_active', true)->where('minimum_quantity', '<=', $quantity)
                ->whereHas('priceList', fn ($query) => $query->where('list_type', 'sales')->where('is_active', true)->when($currencyCode, fn ($scope) => $scope->where('currency_code', strtoupper($currencyCode)))->where(fn ($scope) => $scope->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))->where(fn ($scope) => $scope->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date)))
                ->orderByDesc('minimum_quantity')->first();
            if ($selected) return $selected;
        }
        if ($customer) {
            $specific = CustomerProductPrice::where($base)->where('customer_id', $customer->id)->orderByDesc('minimum_quantity')->first();
            if ($specific) return $specific;
        }
        if ($customer && ($customer->customer_group || $customer->sales_channel)) {
            $agreement = CustomerProductPrice::where($base)->whereNull('customer_id')->where(function ($scope) use ($customer): void {
                $scope->where(fn ($query) => $query->whereNotNull('customer_group')->where('customer_group', $customer->customer_group))
                    ->orWhere(fn ($query) => $query->whereNotNull('sales_channel')->where('sales_channel', $customer->sales_channel));
            })->orderByRaw('CASE WHEN customer_group = ? AND sales_channel = ? THEN 3 WHEN customer_group = ? THEN 2 WHEN sales_channel = ? THEN 1 ELSE 0 END DESC', [$customer->customer_group, $customer->sales_channel, $customer->customer_group, $customer->sales_channel])->orderByDesc('minimum_quantity')->first();
            if ($agreement) return $agreement;
        }
        if (!$customer) return null;

        $listId = $customer->sales_price_list_id;
        if (!$listId) return null;
        return PriceListItem::query()->where('product_id', $product->id)->where('price_list_id', $listId)->where('is_active', true)->where('minimum_quantity', '<=', $quantity)
            ->whereHas('priceList', fn ($query) => $query->where('list_type', 'sales')->where('is_active', true)->when($currencyCode, fn ($scope) => $scope->where('currency_code', strtoupper($currencyCode)))->where(fn ($scope) => $scope->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))->where(fn ($scope) => $scope->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date)))
            ->orderByDesc('minimum_quantity')->first();
    }
}
