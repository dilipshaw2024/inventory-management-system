<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationRule;
use App\Models\Category;
use App\Models\Product;

class InventoryLocationRestrictionService
{
    public function assertAllowed(InventoryLocation $location, Product $product): void
    {
        if (!$this->allows($location, $product)) throw new \RuntimeException('Product '.$product->name.' is not allowed in location '.$location->code.'.');
    }

    public function allows(InventoryLocation $location, Product $product): bool
    {
        if (!function_exists('app') || !app()->bound('db')) return true;
        $locationIds = [];
        $visited = [];
        for ($current = $location; $current && !in_array((int) $current->id, $visited, true); ) {
            $locationIds[] = $current->id;
            $visited[] = (int) $current->id;
            if (!$current->parent_id) break;
            $current = $current->relationLoaded('parent') ? $current->parent : $current->parent()->first();
        }
        $rules = InventoryLocationRule::whereIn('location_id', $locationIds)->where('is_active', true)->get();
        if ($rules->isEmpty()) return true;
        $categoryIds = $this->categoryAncestryIds($product);
        $matching = $rules->filter(fn (InventoryLocationRule $rule): bool => ($rule->product_id !== null && (int) $rule->product_id === (int) $product->id) || ($rule->category_id !== null && in_array((int) $rule->category_id, $categoryIds, true)));
        if ($matching->contains(fn (InventoryLocationRule $rule): bool => $rule->rule_type === 'deny')) return false;
        $allows = $rules->where('rule_type', 'allow');
        return $allows->isEmpty() || $matching->contains(fn (InventoryLocationRule $rule): bool => $rule->rule_type === 'allow');
    }

    /** @return array<int, int> */
    private function categoryAncestryIds(Product $product): array
    {
        if (!$product->category_id) return [];

        $category = $product->relationLoaded('category') ? $product->category : Category::find($product->category_id);
        $ids = [];
        $visited = [];

        while ($category && !in_array((int) $category->id, $visited, true)) {
            $id = (int) $category->id;
            $ids[] = $id;
            $visited[] = $id;
            if (!$category->parent_id) break;
            $category = $category->relationLoaded('parent') ? $category->parent : $category->parent()->first();
        }

        return $ids;
    }
}
