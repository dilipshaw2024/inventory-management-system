<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToProductCompany
{
    protected static function bootBelongsToProductCompany(): void
    {
        static::addGlobalScope('product_company', function (Builder $builder): void {
            $builder->whereHas('product');
            // A valid product alone is not enough for legacy invoice-line
            // tables, which have no company_id. Product child tables such as
            // UOMs and barcodes do not have an invoice relation, so apply the
            // owning-document check only where that relation exists.
            if (method_exists($builder->getModel(), 'invoice')) $builder->whereHas('invoice');
        });
    }
}
