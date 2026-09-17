<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;

trait BelongsToBranchCompany
{
    protected static function bootBelongsToBranchCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $user = auth()->user();
            $companyId = $user?->company_id;
            if ($companyId) {
                $relation = $builder->getModel() instanceof \App\Models\InventoryLocation
                    ? 'warehouse.branch'
                    : 'branch';
                $builder->whereHas($relation, function (Builder $query) use ($companyId, $user): void {
                    $query->where('company_id', $companyId);
                    if ($user?->branch_id) $query->whereKey($user->branch_id);
                });
            }
        });

        static::saving(function (Model $model): void {
            $user = auth()->user();
            if (!$user?->company_id || !$user?->branch_id) return;

            $branch = $model instanceof \App\Models\InventoryLocation
                ? \App\Models\Warehouse::withoutGlobalScopes()->with('branch')->find($model->warehouse_id)?->branch
                : \App\Models\Branch::withoutGlobalScopes()->find($model->branch_id);

            if (!$branch || (int) $branch->company_id !== (int) $user->company_id || (int) $branch->id !== (int) $user->branch_id) {
                throw new AuthorizationException('This user cannot write records outside the assigned branch.');
            }
        });
    }
}
