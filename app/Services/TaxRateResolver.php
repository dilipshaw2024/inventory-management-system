<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TaxRate;
use Carbon\CarbonInterface;

class TaxRateResolver
{
    public function rateFor(Product $product, ?string $date = null, ?string $jurisdiction = null): float
    {
        if (!$product->tax_rate_id) return (float) ($product->tax_rate ?? 0);
        $tax = $product->relationLoaded('taxRate') ? $product->getRelation('taxRate') : $product->taxRate;
        $sameCompany = !$tax || !$product->company_id || !$tax->company_id || (int) $tax->company_id === (int) $product->company_id;
        if ($tax && $sameCompany && $jurisdiction !== null && trim($jurisdiction) !== '') {
            $jurisdictionTax = TaxRate::query()->where('code', $tax->code)->where('jurisdiction', trim($jurisdiction))
                ->where(fn ($query) => $query->where('company_id', $product->company_id)->orWhereNull('company_id'))
                ->where('is_active', true)->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date ?? now()->toDateString()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date ?? now()->toDateString()))
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($jurisdictionTax) return (float) $jurisdictionTax->rate;
        }
        if ($tax && $sameCompany && $tax->is_active && $this->appliesOn($tax, $date)) return (float) $tax->rate;
        return (float) ($product->tax_rate ?? 0);
    }

    private function appliesOn(object $tax, ?string $date): bool
    {
        if (!$date) return true;
        $day = \Carbon\Carbon::parse($date);
        return (!$tax->effective_from || $tax->effective_from->lte($day))
            && (!$tax->effective_until || $tax->effective_until->gte($day));
    }
}
