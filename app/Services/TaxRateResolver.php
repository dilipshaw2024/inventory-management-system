<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TaxRate;
use Carbon\CarbonInterface;

class TaxRateResolver
{
    public function rateFor(Product $product, ?string $date = null, ?string $jurisdiction = null): float
    {
        return (float) ($this->taxFor($product, $date, $jurisdiction)?->rate ?? $product->tax_rate ?? 0);
    }

    public function taxFor(Product $product, ?string $date = null, ?string $jurisdiction = null): ?object
    {
        if (!$product->tax_rate_id) return null;
        $tax = $product->relationLoaded('taxRate') ? $product->getRelation('taxRate') : $product->taxRate;
        if (!$tax) return null;
        if (method_exists($tax, 'loadMissing')) $tax->loadMissing('components');
        $sameCompany = !$product->company_id || !$tax->company_id || (int) $tax->company_id === (int) $product->company_id;
        if (!$sameCompany) return null;
        if ($jurisdiction !== null && trim($jurisdiction) !== '') {
            $jurisdictionTax = TaxRate::with('components')->where('code', $tax->code)->where('jurisdiction', trim($jurisdiction))
                ->where(fn ($query) => $query->where('company_id', $product->company_id)->orWhereNull('company_id'))
                ->where('is_active', true)->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date ?? now()->toDateString()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date ?? now()->toDateString()))
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($jurisdictionTax) return $jurisdictionTax;
        }
        return $tax->is_active && $this->appliesOn($tax, $date) ? $tax : null;
    }

    private function appliesOn(object $tax, ?string $date): bool
    {
        if (!$date) return true;
        $day = \Carbon\Carbon::parse($date);
        return (!$tax->effective_from || $tax->effective_from->lte($day))
            && (!$tax->effective_until || $tax->effective_until->gte($day));
    }
}
