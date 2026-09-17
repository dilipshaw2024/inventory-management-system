<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCustomerCompany
{
    protected static function bootBelongsToCustomerCompany(): void
    {
        static::addGlobalScope('customer_company', function (Builder $builder): void {
            $builder->whereHas('customer');
        });
    }
}
