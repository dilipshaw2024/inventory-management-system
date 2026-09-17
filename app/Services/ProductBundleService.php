<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBundleComponent;
use Illuminate\Support\Collection;

class ProductBundleService
{
    public function components(Product $bundle): Collection
    {
        return ProductBundleComponent::with('componentProduct')->where('bundle_product_id', $bundle->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @param array<int, array{product_id:int, quantity:numeric, sort_order?:int}> $components */
    public function replace(Product $bundle, array $components, int $companyId): Collection
    {
        if (($bundle->product_type ?: 'stock') !== 'bundle') throw new \RuntimeException('Only products marked as bundles can have bundle components.');
        $ids = collect($components)->pluck('product_id')->map(fn ($id): int => (int) $id);
        if ($ids->contains($bundle->id)) throw new \RuntimeException('A bundle cannot contain itself.');
        if ($ids->count() !== $ids->unique()->count()) throw new \RuntimeException('A bundle component may only be listed once.');
        $products = Product::withoutGlobalScopes()->whereIn('id', $ids)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->keyBy('id');
        if ($products->count() !== $ids->unique()->count()) throw new \RuntimeException('One or more bundle components are not authorized for this company.');
        foreach ($components as $component) {
            if ((float) $component['quantity'] <= 0) throw new \RuntimeException('Bundle component quantities must be greater than zero.');
            if ($this->containsBundle($products->get((int) $component['product_id']), $bundle->id, [])) throw new \RuntimeException('Bundle components cannot create a circular bundle reference.');
        }
        ProductBundleComponent::where('bundle_product_id', $bundle->id)->delete();
        foreach ($components as $index => $component) {
            ProductBundleComponent::create(['company_id' => $companyId, 'bundle_product_id' => $bundle->id, 'component_product_id' => (int) $component['product_id'], 'quantity' => $component['quantity'], 'sort_order' => $component['sort_order'] ?? $index]);
        }
        return $this->components($bundle->fresh());
    }

    private function containsBundle(?Product $product, int $targetBundleId, array $visited): bool
    {
        if (!$product || ($product->product_type ?: 'stock') !== 'bundle') return false;
        if ($product->id === $targetBundleId) return true;
        if (isset($visited[$product->id])) return false;
        $visited[$product->id] = true;
        foreach (ProductBundleComponent::where('bundle_product_id', $product->id)->get() as $component) {
            if ($this->containsBundle(Product::withoutGlobalScopes()->find($component->component_product_id), $targetBundleId, $visited)) return true;
        }
        return false;
    }
}
