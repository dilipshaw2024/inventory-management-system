<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'min_amount' => 'decimal:4',
        'max_amount' => 'decimal:4',
        'approval_step' => 'integer',
        'escalation_after_hours' => 'integer',
        'branch_id' => 'integer',
        'category_id' => 'integer',
        'is_active' => 'boolean',
        'escalation_permissions' => 'array',
        'max_escalation_level' => 'integer',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function category() { return $this->belongsTo(Category::class); }

    public static function conflictsWith(array $attributes, ?int $ignoreId = null): bool
    {
        if (array_key_exists('is_active', $attributes) && !$attributes['is_active']) return false;

        $documentType = (string) ($attributes['document_type'] ?? '');
        $step = (int) ($attributes['approval_step'] ?? 1);
        $companyId = $attributes['company_id'] ?? auth()->user()?->company_id;
        $branchId = $attributes['branch_id'] ?: null;
        $categoryId = $attributes['category_id'] ?: null;
        $minValue = $attributes['min_amount'] ?? null;
        $maxValue = $attributes['max_amount'] ?? null;
        $min = $minValue === null || $minValue === '' ? null : (float) $minValue;
        $max = $maxValue === null || $maxValue === '' ? null : (float) $maxValue;

        return static::withoutGlobalScopes()->where('company_id', $companyId)->where('document_type', $documentType)
            ->where('approval_step', $step)->where('is_active', true)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()->contains(function (ApprovalPolicy $policy) use ($branchId, $categoryId, $min, $max): bool {
                $branchOverlap = !$branchId || !$policy->branch_id || (int) $branchId === (int) $policy->branch_id;
                $categoryOverlap = !$categoryId || !$policy->category_id || (int) $categoryId === (int) $policy->category_id;
                $amountOverlap = ($policy->max_amount === null || $min === null || (float) $policy->max_amount >= $min)
                    && ($max === null || $policy->min_amount === null || $max >= (float) $policy->min_amount);
                return $branchOverlap && $categoryOverlap && $amountOverlap;
            });
    }
}
