<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $companyId = auth()->user()?->company_id;
            if ($companyId) {
                $column = $builder->getModel()->getTable().'.company_id';
                $builder->where(fn (Builder $query) => $query->where($column, $companyId)->orWhereNull($column));
            }
        });

        static::creating(function ($model): void {
            if (!$model->company_id && auth()->user()?->company_id) $model->company_id = auth()->user()->company_id;
        });
    }

    public function company() { return $this->belongsTo(\App\Models\Company::class); }
}
