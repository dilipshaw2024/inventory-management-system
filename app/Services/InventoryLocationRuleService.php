<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class InventoryLocationRuleService
{
    public static function existsForCompany(?int $companyId): Exists
    {
        $rule = Rule::exists('inventory_locations', 'id');
        if ($companyId) {
            $rule->where(fn ($query) => $query->whereIn(
                'warehouse_id',
                Warehouse::whereHas('branch', fn ($branch) => $branch->where('company_id', $companyId))->select('id')
            ));
        }
        return $rule;
    }
}
