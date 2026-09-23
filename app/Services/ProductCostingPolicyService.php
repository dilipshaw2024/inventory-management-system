<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\ProductCostingPolicy;
use Carbon\CarbonImmutable;

class ProductCostingPolicyService
{
    public function resolve(Product $product, ?\DateTimeInterface $at = null): array
    {
        $effectiveAt = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $policy = ProductCostingPolicy::where('product_id', $product->id)
            ->where('effective_from', '<=', $effectiveAt)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();

        return [
            'costing_method' => $policy?->costing_method ?: ($product->costing_method ?: 'fifo'),
            'standard_cost' => $policy?->standard_cost !== null ? (float) $policy->standard_cost : ($product->standard_cost !== null ? (float) $product->standard_cost : null),
            'policy_id' => $policy?->id,
            'effective_from' => $policy?->effective_from,
        ];
    }

    public function schedule(Product $product, string $method, ?float $standardCost, \DateTimeInterface $effectiveFrom, ?int $changedBy = null, ?string $reason = null): ProductCostingPolicy
    {
        $effective = CarbonImmutable::instance($effectiveFrom);
        if ($effective->lte(CarbonImmutable::now())) throw new \InvalidArgumentException('A scheduled costing policy must use a future effective date.');
        if ($method === 'standard' && $standardCost === null) throw new \InvalidArgumentException('Standard costing requires a standard cost.');

        return ProductCostingPolicy::create([
            'product_id' => $product->id, 'costing_method' => $method, 'standard_cost' => $standardCost,
            'effective_from' => $effective, 'changed_by' => $changedBy, 'reason' => $reason,
        ]);
    }

    public function recordCurrent(Product $product, string $method, ?float $standardCost, ?int $changedBy = null, ?string $reason = null): ProductCostingPolicy
    {
        if ($method === 'standard' && $standardCost === null) throw new \InvalidArgumentException('Standard costing requires a standard cost.');
        $effective = CarbonImmutable::now();
        $policy = ProductCostingPolicy::create([
            'product_id' => $product->id, 'costing_method' => $method, 'standard_cost' => $standardCost,
            'effective_from' => $effective, 'changed_by' => $changedBy, 'reason' => $reason,
        ]);
        ProductCostHistory::create([
            'product_id' => $product->id, 'old_costing_method' => $product->getOriginal('costing_method'),
            'new_costing_method' => $method, 'old_standard_cost' => $product->getOriginal('standard_cost'),
            'new_standard_cost' => $standardCost, 'effective_at' => $effective, 'changed_by' => $changedBy, 'reason' => $reason,
        ]);
        return $policy;
    }
}
