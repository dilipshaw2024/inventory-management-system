<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerProductPrice;
use App\Models\SupplierProductPrice;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\Services\IntegrationCursorService;

class PricingIntegrationController extends Controller
{
    public function promotions(Request $request)
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:80'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $promotions = Promotion::with(['product:id,name,sku', 'category:id,name', 'customer:id,name'])
            ->when($data['code'] ?? null, fn ($query, $code) => $query->where('code', strtoupper($code)))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($promotions, $request, 'pricing.promotions', (int) ($data['per_page'] ?? 50));
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $this->assertSalesWrite($request);
        $companyId = $request->user()?->company_id;
        $data = $this->promotionData($request, $companyId);
        $existing = Promotion::where('code', strtoupper($data['code']))->first();
        if ($existing) return response()->json(['data' => $existing->load('product', 'category', 'customer'), 'status' => 'duplicate_ignored']);
        $promotion = DB::transaction(function () use ($data, $companyId, $request): Promotion {
            $promotion = Promotion::create($data + ['company_id' => $companyId, 'code' => strtoupper($data['code']), 'is_active' => true, 'created_by' => $request->user()?->id]);
            app(AuditService::class)->record('promotion.created', $promotion, null, $promotion->toArray());
            return $promotion;
        });
        return response()->json(['data' => $promotion->load('product', 'category', 'customer'), 'status' => 'created'], 201);
    }

    public function updatePromotion(Request $request, int $id): JsonResponse
    {
        $this->assertSalesWrite($request); $promotion = Promotion::findOrFail($id); $companyId = $request->user()?->company_id;
        $data = $this->promotionData($request, $companyId, true); $before = $promotion->only(array_keys($data));
        if ($promotion->usage_count > 0 && array_key_exists('type', $data)) abort(422, 'A redeemed promotion cannot change discount type.');
        $promotion->update(array_merge($data, ['code' => strtoupper($data['code'] ?? $promotion->code)]));
        app(AuditService::class)->record('promotion.updated', $promotion, $before, $promotion->fresh()->only(array_keys($data)));
        return response()->json(['data' => $promotion->fresh()->load('product', 'category', 'customer'), 'status' => 'updated']);
    }

    public function deactivatePromotion(Request $request, int $id): JsonResponse
    {
        $this->assertSalesWrite($request); $promotion = Promotion::findOrFail($id);
        if ($promotion->is_active) { $promotion->update(['is_active' => false]); app(AuditService::class)->record('promotion.deactivated', $promotion, ['is_active' => true], ['is_active' => false]); }
        return response()->json(['data' => $promotion->fresh(), 'status' => 'deactivated']);
    }

    public function storePriceList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'list_type' => ['required', 'in:sales,purchase'],
            'currency_code' => ['required', 'string', 'size:3'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('price_lists', 'external_reference')->where(fn ($query) => $query->where('company_id', $request->user()?->company_id))],
        ]);
        $this->assertAccess($request, $data['list_type']);
        $companyId = $request->user()?->company_id;
        if (PriceList::where('company_id', $companyId)->where('name', $data['name'])->exists()) abort(422, 'This price-list name already exists.');
        $list = DB::transaction(function () use ($data, $companyId): PriceList {
            $list = PriceList::create($data + ['company_id' => $companyId, 'currency_code' => strtoupper($data['currency_code']), 'is_active' => true]);
            app(AuditService::class)->record('price_list.created', $list, null, $list->toArray());
            return $list;
        });
        return response()->json(['data' => $list, 'status' => 'created'], 201);
    }

    public function storePriceListItem(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'price_list_id' => ['sometimes', 'integer'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'minimum_quantity' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('price_list_items', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);
        if (isset($data['price_list_id']) && (int) $data['price_list_id'] !== $id) abort(422, 'The request price list does not match the URL.');
        $list = PriceList::findOrFail($id);
        $this->assertAccess($request, $list->list_type);
        if (!$list->is_active) abort(422, 'Items cannot be added to an inactive price list.');
        if (PriceListItem::where('price_list_id', $list->id)->where('product_id', $data['product_id'])->where('minimum_quantity', $data['minimum_quantity'])->exists()) abort(422, 'This product quantity break already exists.');
        $item = DB::transaction(function () use ($data, $list, $companyId): PriceListItem {
            $item = PriceListItem::create(['price_list_id' => $list->id] + $data + ['company_id' => $companyId, 'discount_percent' => $data['discount_percent'] ?? 0, 'is_active' => true]);
            app(AuditService::class)->record('price_list_item.created', $item, null, $item->toArray());
            return $item;
        });
        return response()->json(['data' => $item->load('priceList', 'product'), 'status' => 'created'], 201);
    }

    public function deactivatePriceList(Request $request, int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);
        $this->assertAccess($request, $list->list_type);
        if ($list->is_active) {
            $list->update(['is_active' => false]);
            app(AuditService::class)->record('price_list.deactivated', $list, ['is_active' => true], ['is_active' => false]);
        }
        return response()->json(['data' => $list->fresh(), 'status' => 'deactivated']);
    }

    private function assertAccess(Request $request, string $listType): void
    {
        $ability = $listType === 'sales' ? 'sales:write' : 'purchasing:write';
        if (!$request->user()?->tokenCan($ability) && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify this price-list type.');
    }

    private function assertSalesWrite(Request $request): void
    {
        if (!$request->user()?->tokenCan('sales:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify promotions.');
    }

    private function promotionData(Request $request, ?int $companyId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'code' => [$required, 'string', 'max:80', 'alpha_dash', Rule::unique('promotions', 'code')->ignore($request->route('id'))->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => [$required, 'string', 'max:150'], 'type' => [$required, 'in:percentage,fixed,bogo'], 'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'buy_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'], 'get_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'minimum_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'customer_id' => ['sometimes', 'nullable', 'integer', $owned('customers')], 'product_id' => ['sometimes', 'nullable', 'integer', $owned('products')], 'category_id' => ['sometimes', 'nullable', 'integer', $owned('categories')],
            'starts_on' => ['sometimes', 'nullable', 'date'], 'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'], 'is_active' => ['sometimes', 'boolean'], 'stackable' => ['sometimes', 'boolean'],
        ]);
        $existing = $partial ? Promotion::find($request->route('id')) : null;
        $effectiveType = $data['type'] ?? $existing?->type;
        $discountValue = array_key_exists('discount_value', $data) ? $data['discount_value'] : $existing?->discount_value;
        $buyQuantity = array_key_exists('buy_quantity', $data) ? $data['buy_quantity'] : $existing?->buy_quantity;
        $getQuantity = array_key_exists('get_quantity', $data) ? $data['get_quantity'] : $existing?->get_quantity;
        if ($effectiveType !== 'bogo' && ($discountValue === null || (float) $discountValue <= 0)) abort(422, 'A percentage or fixed promotion requires a positive discount value.');
        if ($effectiveType === 'bogo' && ($buyQuantity === null || $getQuantity === null || (float) $buyQuantity <= 0 || (float) $getQuantity <= 0)) abort(422, 'A BOGO promotion requires positive buy and get quantities.');
        if ($effectiveType === 'bogo' && !array_key_exists('discount_value', $data)) $data['discount_value'] = 0;
        if ($effectiveType === 'percentage' && isset($data['discount_value']) && (float) $data['discount_value'] > 100) abort(422, 'Percentage discount cannot exceed 100.');
        return $data;
    }

    public function priceLists(Request $request)
    {
        $data = $request->validate(['list_type' => ['nullable', 'in:sales,purchase'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $lists = PriceList::with(['items.product:id,name,sku'])
            ->where('is_active', true)
            ->when($data['list_type'] ?? null, fn ($query, $type) => $query->where('list_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($lists, $request, 'pricing.lists', (int) ($data['per_page'] ?? 50));
    }

    public function supplierPrices(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'updated_since' => ['nullable', 'date'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $prices = SupplierProductPrice::query()
            ->with(['supplier:id,name', 'product:id,name,sku'])
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->where('is_active', true)
            ->when($data['currency_code'] ?? null, fn ($query, $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when($data['quantity'] ?? null, fn ($query, $quantity) => $query->where('minimum_quantity', '<=', $quantity))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($prices, $request, 'pricing.supplier-prices', 100);
    }

    public function customerPrices(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_group' => ['nullable', 'string', 'max:100'],
            'sales_channel' => ['nullable', 'string', 'max:50'],
            'product_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'updated_since' => ['nullable', 'date'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $prices = CustomerProductPrice::query()
            ->with(['customer:id,name', 'product:id,name,sku'])
            ->when(array_key_exists('customer_id', $data), fn ($query) => $query->where('customer_id', $data['customer_id']))
            ->when($data['customer_group'] ?? null, fn ($query, $group) => $query->where('customer_group', $group))
            ->when($data['sales_channel'] ?? null, fn ($query, $channel) => $query->where('sales_channel', $channel))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->where('is_active', true)
            ->when($data['currency_code'] ?? null, fn ($query, $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when($data['quantity'] ?? null, fn ($query, $quantity) => $query->where('minimum_quantity', '<=', $quantity))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($prices, $request, 'pricing.customer-prices', 100);
    }
}
