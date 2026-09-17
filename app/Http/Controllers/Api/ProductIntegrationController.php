<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Brand;
use App\Models\ProductBarcode;
use App\Models\ProductAttributeAssignment;
use App\Models\ProductAttributeValue;
use App\Models\ProductAttribute;
use App\Models\ProductUom;
use App\Models\InventoryLocation;
use App\Services\AuditService;
use App\Services\InventoryAvailabilityService;
use App\Services\NumberingSequenceService;
use App\Services\ProductLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductIntegrationController extends Controller
{
    public function media(Request $request, int $id): JsonResponse
    {
        $product = $this->companyScope(Product::query())->findOrFail($id);
        $attachments = $product->attachments()->orderByDesc('is_primary')->orderBy('attachment_type')->orderByDesc('version')->get([
            'id', 'original_name', 'mime_type', 'size_bytes', 'attachment_type', 'title', 'version', 'is_primary', 'created_at',
        ]);
        return response()->json(['data' => $attachments, 'product_id' => $product->id]);
    }

    public function uoms(Request $request, int $id): JsonResponse
    {
        $product = $this->companyScope(Product::query())->findOrFail($id);
        return response()->json(['data' => $product->uoms()->with('unit')->orderBy('id')->get(), 'product_id' => $product->id]);
    }

    public function storeUom(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $product = $this->companyScope(Product::query())->findOrFail($id);
        $data = $request->validate([
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'conversion_to_stock' => ['required', 'numeric', 'gt:0'], 'usage' => ['nullable', 'in:purchase,sales,both'],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'], 'is_active' => ['nullable', 'boolean'],
        ]);
        if ((int) $data['unit_id'] === (int) $product->unit_id) abort(422, 'The stock unit does not need a conversion rule.');
        $unit = $this->companyScope(Unit::query())->findOrFail($data['unit_id']);
        if ($product->unit && $unit->dimension && $product->unit->dimension && $product->unit->dimension !== $unit->dimension) abort(422, 'The UOM dimension must match the product stock unit.');
        if ($product->uoms()->where('unit_id', $unit->id)->exists()) abort(422, 'A conversion rule already exists for this UOM. Update the existing rule instead.');
        $uom = DB::transaction(function () use ($data, $product): ProductUom {
            $uom = ProductUom::create(['product_id' => $product->id, 'unit_id' => $data['unit_id'], 'conversion_to_stock' => $data['conversion_to_stock'], 'usage' => $data['usage'] ?? 'both', 'decimal_places' => $data['decimal_places'] ?? 3, 'is_active' => $data['is_active'] ?? true]);
            app(AuditService::class)->record('product_uom.created', $uom, null, $uom->toArray() + ['api' => true]);
            return $uom;
        });
        return response()->json(['data' => $uom->load('unit'), 'status' => 'created'], 201);
    }

    public function updateUom(Request $request, int $id, int $uomId): JsonResponse
    {
        $this->assertWriteAccess($request);
        $product = $this->companyScope(Product::query())->findOrFail($id);
        $uom = $product->uoms()->whereKey($uomId)->firstOrFail();
        $data = $request->validate(['conversion_to_stock' => ['sometimes', 'numeric', 'gt:0'], 'usage' => ['sometimes', 'in:purchase,sales,both'], 'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:6'], 'is_active' => ['sometimes', 'boolean']]);
        $before = $uom->only(array_keys($data));
        $uom->update($data);
        app(AuditService::class)->record('product_uom.updated', $uom, $before, $uom->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $uom->fresh()->load('unit'), 'status' => 'updated']);
    }

    public function deactivateUom(Request $request, int $id, int $uomId): JsonResponse
    {
        $this->assertWriteAccess($request);
        $product = $this->companyScope(Product::query())->findOrFail($id);
        $uom = $product->uoms()->whereKey($uomId)->firstOrFail();
        $uom->update(['is_active' => false]);
        app(AuditService::class)->record('product_uom.deactivated', $uom, ['is_active' => true], ['is_active' => false, 'api' => true]);
        return response()->json(['data' => $uom->fresh()->load('unit'), 'status' => 'deactivated']);
    }

    public function attributes(Request $request): JsonResponse
    {
        $attributes = ProductAttribute::with('values')
            ->when($request->input('is_active') !== null, fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->input('updated_since'), fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(\App\Services\IntegrationCursorService::class)->paginate($attributes, $request, 'inventory.attributes', (int) $request->input('per_page', 50));
    }

    public function storeAttribute(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('product_attributes', 'name')->where(fn ($query) => $query->where('company_id', $companyId))], 'is_active' => ['nullable', 'boolean']]);
        $attribute = DB::transaction(function () use ($data, $request): ProductAttribute {
            $attribute = ProductAttribute::create($data + ['company_id' => $request->user()?->company_id, 'is_active' => true]);
            app(AuditService::class)->record('product_attribute.created', $attribute, null, $attribute->toArray());
            return $attribute;
        });
        return response()->json(['data' => $attribute->load('values'), 'status' => 'created'], 201);
    }

    public function storeAttributeValue(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $attribute = ProductAttribute::findOrFail($id);
        $data = $request->validate(['value' => ['required', 'string', 'max:100', Rule::unique('product_attribute_values', 'value')->where(fn ($query) => $query->where('attribute_id', $attribute->id))]]);
        $value = DB::transaction(function () use ($data, $attribute): ProductAttributeValue {
            $value = ProductAttributeValue::create(['company_id' => $attribute->company_id ?: auth()->user()?->company_id, 'attribute_id' => $attribute->id, 'value' => $data['value']]);
            app(AuditService::class)->record('product_attribute_value.created', $value, null, $value->toArray());
            return $value;
        });
        return response()->json(['data' => $value->load('attribute'), 'status' => 'created'], 201);
    }

    public function updateCategoryRequirements(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $category = Category::whereKey($id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        $data = $request->validate([
            'attribute_ids' => ['required', 'array', 'max:50'],
            'attribute_ids.*' => ['integer', Rule::exists('product_attributes', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
        ]);
        $attributeIds = array_values(array_unique(array_map('intval', $data['attribute_ids'])));
        $attributes = ProductAttribute::whereIn('id', $attributeIds)->where('is_active', true)->get();
        if ($attributes->count() !== count($attributeIds)) abort(422, 'All required attributes must be active and authorized for this company.');
        $before = ['required_attribute_ids' => $category->required_attribute_ids];
        $category->update(['required_attribute_ids' => $attributeIds]);
        app(AuditService::class)->record('category.attribute_requirements.updated', $category, $before, ['required_attribute_ids' => $attributeIds]);
        return response()->json(['data' => $category->fresh(), 'status' => 'updated']);
    }

    public function storeVariant(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $parent = Product::findOrFail($id);
        if ($parent->is_variant) abort(422, 'A variant cannot be used as a variant parent.');
        if (!app(ProductLifecycleService::class)->isAvailable($parent)) abort(422, 'Variants can only be created from an active parent product.');
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('products', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:255'], 'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'auto_sku' => ['nullable', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'attribute_value_id' => ['nullable', 'array'],
            'attribute_value_id.*' => ['integer', Rule::exists('product_attribute_values', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
        ]);
        if (empty($data['sku']) && ($data['auto_sku'] ?? true)) $data['sku'] = $this->nextSku($companyId);
        if (!empty($data['external_reference'])) {
            $existing = Product::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('parentProduct', 'attributeAssignments.attribute', 'attributeAssignments.value'), 'status' => 'duplicate_ignored']);
        }
        $valueIds = array_values(array_unique($data['attribute_value_id'] ?? []));
        $values = ProductAttributeValue::with('attribute')->whereIn('id', $valueIds)->get();
        if ($values->count() !== count($valueIds) || $values->pluck('attribute_id')->unique()->count() !== $values->count()) abort(422, 'Each variant can have only one value per attribute.');
        if ($values->contains(fn (ProductAttributeValue $value): bool => !$value->attribute?->is_active)) abort(422, 'Inactive attributes cannot be assigned to a variant.');
        if ($values->contains(fn (ProductAttributeValue $value): bool => (int) ($value->attribute?->company_id ?: $companyId) !== (int) $companyId)) abort(422, 'Attribute value belongs to a different company.');
        $requiredAttributes = array_values(array_filter(array_map('intval', $parent->category?->required_attribute_ids ?? [])));
        if (collect($requiredAttributes)->diff($values->pluck('attribute_id')->map(fn ($attributeId): int => (int) $attributeId))->isNotEmpty()) abort(422, 'This category requires values for all configured variant attributes.');
        $variant = DB::transaction(function () use ($data, $parent, $values, $companyId): Product {
            $variant = Product::create([
                'company_id' => $parent->company_id ?: $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'parent_product_id' => $parent->id, 'is_variant' => true, 'name' => $data['name'], 'supplier_id' => $parent->supplier_id,
                'unit_id' => $parent->unit_id, 'category_id' => $parent->category_id, 'brand_id' => $parent->brand_id,
                'sku' => $data['sku'] ?? null, 'barcode' => $data['barcode'] ?? null, 'purchase_price' => $parent->purchase_price,
                'sales_price' => $parent->sales_price, 'tax_rate' => $parent->tax_rate, 'tracking_type' => $parent->tracking_type,
                'product_type' => $parent->product_type ?: 'stock', 'lifecycle_status' => $parent->lifecycle_status ?: 'active',
                'can_purchase' => (bool) ($parent->can_purchase ?? true), 'can_sell' => (bool) ($parent->can_sell ?? true), 'is_stock_item' => (bool) ($parent->is_stock_item ?? true),
                'weight_kg' => $parent->weight_kg, 'length_m' => $parent->length_m, 'width_m' => $parent->width_m, 'height_m' => $parent->height_m,
                'quantity' => 0, 'status' => 1, 'created_by' => auth()->id(),
            ]);
            foreach ($values as $value) ProductAttributeAssignment::create(['company_id' => $variant->company_id, 'product_id' => $variant->id, 'attribute_id' => $value->attribute_id, 'attribute_value_id' => $value->id]);
            app(AuditService::class)->record('product_variant.created', $variant, null, $variant->toArray());
            return $variant;
        });
        return response()->json(['data' => $variant->load('parentProduct', 'attributeAssignments.attribute', 'attributeAssignments.value'), 'status' => 'created'], 201);
    }

    public function storeBarcode(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $product = Product::findOrFail($id);
        $externalReference = $request->input('external_reference');
        if (is_string($externalReference) && $externalReference !== '') {
            $existing = ProductBarcode::where('product_id', $product->id)->where('external_reference', $externalReference)->first();
            if ($existing) return response()->json(['data' => $existing->load('product'), 'status' => 'duplicate_ignored']);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120', Rule::unique('product_barcodes', 'code')],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('product_barcodes', 'external_reference')->where(fn ($query) => $query->where('product_id', $product->id))],
            'type' => ['required', 'in:barcode,qrcode'], 'is_primary' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $barcode = DB::transaction(function () use ($data, $product): ProductBarcode {
            if (!empty($data['is_primary'])) ProductBarcode::where('product_id', $product->id)->update(['is_primary' => false]);
            $barcode = ProductBarcode::create($data + ['product_id' => $product->id, 'is_primary' => (bool) ($data['is_primary'] ?? false)]);
            if ($barcode->is_primary || !$product->barcode) $product->update(['barcode' => $barcode->code]);
            app(AuditService::class)->record('product_barcode.created', $barcode, null, $barcode->toArray());
            return $barcode;
        });
        return response()->json(['data' => $barcode->load('product'), 'status' => 'created'], 201);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null && !InventoryLocation::whereKey($locationId)->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }

        $code = trim($data['code']);
        $barcode = ProductBarcode::with('product')->where('code', $code)->first();
        $product = $barcode?->product;
        if (!$product) {
            $product = Product::with(['category', 'unit', 'brand', 'barcodes'])
                ->where(function ($query) use ($code): void {
                    $query->where('barcode', $code)->orWhere('sku', $code);
                })->first();
        }
        if (!$product || !$product->status) {
            return response()->json(['message' => 'Product code was not found or is inactive.'], 404);
        }

        $availability = app(InventoryAvailabilityService::class)->available($product, true, $locationId, auth()->user()?->company_id);
        return response()->json([
            'data' => [
                'product' => $product->only(['id', 'name', 'sku', 'barcode', 'sales_price', 'tax_rate', 'tax_rate_id', 'tracking_type', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'weight_kg', 'length_m', 'width_m', 'height_m']),
                'barcode_type' => $barcode?->type ?? ($product->sku === $code ? 'sku' : 'barcode'),
                'matched_code' => $code,
                'location_id' => $locationId,
                'available' => $availability,
            ],
            'status' => 'found',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $data = $this->validated($request, $companyId);
        if (empty($data['sku']) && ($data['auto_sku'] ?? true)) $data['sku'] = $this->nextSku($companyId);
        if (!empty($data['external_reference'])) {
            $existing = Product::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('category', 'unit', 'brand', 'supplier', 'taxRate'), 'status' => 'duplicate_ignored']);
        }
        $product = DB::transaction(function () use ($data, $companyId): Product {
            $product = Product::create($this->productAttributes($data) + ['company_id' => $companyId, 'quantity' => 0, 'created_by' => auth()->id()]);
            app(AuditService::class)->record('product.created', $product, null, $product->toArray());
            return $product;
        });
        return response()->json(['data' => $product->load('category', 'unit', 'brand', 'supplier', 'taxRate'), 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $product = Product::lockForUpdate()->findOrFail($id);
        $data = $this->validated($request, $companyId, $product->id);
        app(ProductLifecycleService::class)->assertTransitionAllowed($product, $data);
        if ($product->tracking_type !== $data['tracking_type'] && app(\App\Services\ProductLifecycleService::class)->hasStock($product)) abort(422, 'Tracking type cannot change while the product has stock.');
        $before = $product->only(array_keys($this->productAttributes($data)));
        DB::transaction(function () use ($product, $data, $before): void {
            $product->update($this->productAttributes($data) + ['updated_by' => auth()->id()]);
            app(AuditService::class)->record('product.updated', $product, $before, $product->fresh()->only(array_keys($this->productAttributes($data))));
        });
        return response()->json(['data' => $product->fresh()->load('category', 'unit', 'brand', 'supplier', 'taxRate'), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $product = Product::findOrFail($id);
        if (app(\App\Services\ProductLifecycleService::class)->hasStock($product)) abort(422, 'Products with stock cannot be deactivated.');
        $before = ['status' => $product->status];
        $product->update(['status' => 0, 'updated_by' => auth()->id()]);
        app(AuditService::class)->record('product.deactivated', $product, $before, ['status' => 0]);
        return response()->json(['data' => $product->fresh(), 'status' => 'deactivated']);
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        return $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('products', 'external_reference')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:255'], 'supplier_id' => ['required', 'integer', $owned('suppliers')],
            'unit_id' => ['required', 'integer', $owned('units')], 'category_id' => ['required', 'integer', $owned('categories')],
            'brand_id' => ['nullable', 'integer', $owned('brands')],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'hsn_sac_code' => ['nullable', 'string', 'max:30'], 'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'], 'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'gte:min_stock'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'tracking_type' => ['required', 'in:none,batch,serial'],
            'tax_rate_id' => ['nullable', 'integer', $owned('tax_rates')],
            'status' => ['nullable', 'boolean'],
            'product_type' => ['nullable', 'in:stock,service,consumable,asset,bundle'],
            'lifecycle_status' => ['nullable', 'in:draft,active,discontinued,blocked,archived'],
            'can_purchase' => ['nullable', 'boolean'], 'can_sell' => ['nullable', 'boolean'], 'is_stock_item' => ['nullable', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'], 'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function productAttributes(array $data): array
    {
        return collect($data)->only(['external_reference', 'name', 'supplier_id', 'unit_id', 'category_id', 'brand_id', 'sku', 'barcode', 'hsn_sac_code', 'purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'tax_rate_id', 'tracking_type', 'status', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'weight_kg', 'length_m', 'width_m', 'height_m'])->all();
    }

    private function nextSku(?int $companyId): string
    {
        do {
            $sku = app(NumberingSequenceService::class)->nextOrFallback('product', 'SKU-'.Str::upper(Str::random(10)), $companyId);
        } while (Product::where('sku', $sku)->exists());
        return $sku;
    }

    private function assertWriteAccess(Request $request): void
    {
        if (!$request->user()?->tokenCan('inventory:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify the product master.');
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
