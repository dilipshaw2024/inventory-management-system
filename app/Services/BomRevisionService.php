<?php

namespace App\Services;

use App\Models\BillOfMaterial;
use Carbon\Carbon;

class BomRevisionService
{
    public function assertNoActiveOverlap(int $productId, ?string $effectiveFrom, ?string $effectiveUntil, ?int $companyId, ?int $ignoreId = null): void
    {
        $start = $effectiveFrom ? Carbon::parse($effectiveFrom)->toDateString() : '0001-01-01';
        $end = $effectiveUntil ? Carbon::parse($effectiveUntil)->toDateString() : '9999-12-31';
        $overlap = BillOfMaterial::withoutGlobalScopes()
            ->where('product_id', $productId)->where('is_active', true)
            ->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $start))
            ->exists();
        if ($overlap) throw new \RuntimeException('Another active BOM revision overlaps this product and effective date range. Close or use a non-overlapping revision.');
    }
}
