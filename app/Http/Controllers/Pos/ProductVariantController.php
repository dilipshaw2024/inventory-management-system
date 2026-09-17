<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeAssignment;
use App\Models\ProductAttributeValue;
use App\Services\AuditService;
use App\Services\ProductLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function index()
    {
        $parents = Product::whereNull('parent_product_id')->where('status', 1)->orderBy('name')->get();
        $variants = Product::with(['parentProduct', 'attributeAssignments.attribute', 'attributeAssignments.value'])->where('is_variant', true)->latest()->paginate(40);
        $attributes = ProductAttribute::with('values')->where('is_active', true)->orderBy('name')->get();
        return view('backend.product.variants', compact('parents', 'variants', 'attributes'));
    }

    public function storeAttribute(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('product_attributes', 'name')->where(fn ($query) => $query->where('company_id', $companyId))], 'value' => ['required', 'string', 'max:100']]);
        $attribute = ProductAttribute::create(['company_id' => auth()->user()?->company_id, 'name' => $data['name']]);
        ProductAttributeValue::create(['company_id' => $attribute->company_id, 'attribute_id' => $attribute->id, 'value' => $data['value']]);
        app(AuditService::class)->record('product_attribute.created', $attribute, null, $attribute->toArray());
        return back()->with(['message' => 'Attribute and first value created.', 'alert-type' => 'success']);
    }

    public function storeValue(Request $request)
    {
        $data = $request->validate(['attribute_id' => ['required', Rule::exists('product_attributes', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'value' => ['required', 'string', 'max:100']]);
        $attribute = ProductAttribute::findOrFail($data['attribute_id']);
        $value = ProductAttributeValue::firstOrCreate($data, ['company_id' => $attribute->company_id ?: auth()->user()?->company_id]);
        app(AuditService::class)->record('product_attribute_value.created', $value, null, $value->toArray());
        return back()->with(['message' => 'Attribute value saved.', 'alert-type' => 'success']);
    }

    public function storeVariant(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['parent_product_id' => ['required', 'integer', $productScope], 'name' => ['required', 'string', 'max:255'], 'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'attribute_value_id' => ['nullable', 'array'], 'attribute_value_id.*' => ['nullable', Rule::exists('product_attribute_values', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]]);
        $variant = DB::transaction(function () use ($data): Product {
            $parent = Product::findOrFail($data['parent_product_id']);
            if ($parent->is_variant || !app(ProductLifecycleService::class)->isAvailable($parent)) throw new \RuntimeException('Variants can only be created from an active parent product.');
            $variant = Product::create(['company_id' => $parent->company_id ?: auth()->user()?->company_id, 'parent_product_id' => $parent->id, 'is_variant' => true, 'name' => $data['name'], 'supplier_id' => $parent->supplier_id, 'unit_id' => $parent->unit_id, 'category_id' => $parent->category_id, 'brand_id' => $parent->brand_id, 'sku' => $data['sku'] ?? null, 'barcode' => $data['barcode'] ?? null, 'purchase_price' => $parent->purchase_price, 'sales_price' => $parent->sales_price, 'tax_rate' => $parent->tax_rate, 'tracking_type' => $parent->tracking_type, 'product_type' => $parent->product_type ?: 'stock', 'lifecycle_status' => $parent->lifecycle_status ?: 'active', 'can_purchase' => (bool) ($parent->can_purchase ?? true), 'can_sell' => (bool) ($parent->can_sell ?? true), 'is_stock_item' => (bool) ($parent->is_stock_item ?? true), 'weight_kg' => $parent->weight_kg, 'length_m' => $parent->length_m, 'width_m' => $parent->width_m, 'height_m' => $parent->height_m, 'quantity' => 0, 'status' => 1, 'created_by' => auth()->id()]);
            $valueIds = array_values(array_unique(array_filter($data['attribute_value_id'] ?? [])));
            $values = ProductAttributeValue::with('attribute')->whereIn('id', $valueIds)->get();
            if ($values->count() !== count($valueIds) || $values->pluck('attribute_id')->unique()->count() !== $values->count()) throw new \RuntimeException('Each variant can have only one value per attribute.');
            $requiredAttributes = array_values(array_filter(array_map('intval', $parent->category?->required_attribute_ids ?? [])));
            if (collect($requiredAttributes)->diff($values->pluck('attribute_id')->map(fn ($id): int => (int) $id))->isNotEmpty()) throw new \RuntimeException('This category requires values for all configured variant attributes.');
            foreach ($values as $value) {
                if (!$value->attribute?->is_active) throw new \RuntimeException('Inactive attributes cannot be assigned to a variant.');
                if ((int) ($value->company_id ?: $parent->company_id) !== (int) $parent->company_id || (int) ($value->attribute?->company_id ?: $parent->company_id) !== (int) $parent->company_id) throw new \RuntimeException('Attribute value belongs to a different company.');
                ProductAttributeAssignment::create(['company_id' => $variant->company_id, 'product_id' => $variant->id, 'attribute_id' => $value->attribute_id, 'attribute_value_id' => $value->id]);
            }
            return $variant;
        });
        app(AuditService::class)->record('product_variant.created', $variant, null, $variant->toArray());
        return back()->with(['message' => 'Product variant created.', 'alert-type' => 'success']);
    }
}
