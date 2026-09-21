<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerProductPrice;
use App\Models\Customer;
use App\Models\ApprovalPolicy;
use App\Models\SupplierProductPrice;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\Services\IntegrationCursorService;
use App\Services\ProductSpreadsheetService;
use App\Services\SupplierProductPriceImportService;

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
        $companyId = $request->user()?->company_id;
        if ($request->filled('external_reference')) {
            $existing = PriceList::where('company_id', $companyId)->where('external_reference', $request->input('external_reference'))->first();
            if ($existing) {
                $this->assertAccess($request, $existing->list_type);
                return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
            }
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'list_type' => ['required', 'in:sales,purchase'],
            'currency_code' => ['required', 'string', 'size:3'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('price_lists', 'external_reference')->where(fn ($query) => $query->where('company_id', $request->user()?->company_id))],
        ]);
        $this->assertAccess($request, $data['list_type']);
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
        if ($request->filled('external_reference')) {
            $existing = PriceListItem::with('priceList', 'product')->where('company_id', $companyId)->where('external_reference', $request->input('external_reference'))->first();
            if ($existing) {
                $this->assertAccess($request, $existing->priceList?->list_type ?? 'sales');
                return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
            }
        }
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

    public function updatePriceList(Request $request, int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);
        $this->assertAccess($request, $list->list_type);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('price_lists', 'name')->ignore($list->id)->where(fn ($query) => $query->where('company_id', $request->user()?->company_id))],
            'currency_code' => ['sometimes', 'string', 'size:3'], 'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $list->is_active === false) {
            return response()->json(['data' => $list, 'status' => 'already_inactive']);
        }
        $before = $list->only(array_keys($data));
        if (isset($data['currency_code'])) $data['currency_code'] = strtoupper($data['currency_code']);
        $list->update($data);
        app(AuditService::class)->record('price_list.updated', $list, $before, $list->fresh()->only(array_keys($data)));
        return response()->json(['data' => $list->fresh(), 'status' => 'updated']);
    }

    public function updatePriceListItem(Request $request, int $id): JsonResponse
    {
        $item = PriceListItem::with('priceList')->findOrFail($id);
        $list = $item->priceList;
        $this->assertAccess($request, $list->list_type);
        if (!$list->is_active) abort(422, 'Items cannot be updated on an inactive price list.');
        $data = $request->validate([
            'minimum_quantity' => ['sometimes', 'numeric', 'gt:0'], 'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $minimumQuantity = $data['minimum_quantity'] ?? $item->minimum_quantity;
        if (PriceListItem::where('price_list_id', $list->id)->where('product_id', $item->product_id)->where('minimum_quantity', $minimumQuantity)->where('id', '<>', $item->id)->exists()) abort(422, 'This product quantity break already exists.');
        $before = $item->only(array_keys($data));
        $item->update($data);
        app(AuditService::class)->record('price_list_item.updated', $item, $before, $item->fresh()->only(array_keys($data)));
        return response()->json(['data' => $item->fresh('priceList', 'product'), 'status' => 'updated']);
    }

    public function deactivatePriceListItem(Request $request, int $id): JsonResponse
    {
        $item = PriceListItem::with('priceList')->findOrFail($id);
        $this->assertAccess($request, $item->priceList->list_type);
        if ($item->is_active) {
            $item->update(['is_active' => false]);
            app(AuditService::class)->record('price_list_item.deactivated', $item, ['is_active' => true], ['is_active' => false]);
        }
        return response()->json(['data' => $item->fresh('priceList', 'product'), 'status' => 'deactivated']);
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

    public function assignCustomerPriceList(Request $request): JsonResponse
    {
        $this->assertSalesWrite($request);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('list_type', 'sales'))],
        ]);
        $customer = Customer::whereKey($data['customer_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        $list = !empty($data['price_list_id']) ? PriceList::where('company_id', $companyId)->where('list_type', 'sales')->findOrFail($data['price_list_id']) : null;
        if ($list && !$list->is_active) abort(422, 'An inactive sales price list cannot be assigned.');
        $before = ['sales_price_list_id' => $customer->sales_price_list_id];
        $customer->update(['sales_price_list_id' => $list?->id]);
        app(AuditService::class)->record('customer.sales_price_list_updated', $customer, $before, $customer->fresh()->only(['sales_price_list_id']));
        return response()->json(['data' => $customer->fresh('salesPriceList'), 'status' => 'updated']);
    }

    public function assignSupplierPriceList(Request $request): JsonResponse
    {
        $this->assertAccess($request, 'purchase');
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('list_type', 'purchase'))],
        ]);
        $supplier = Supplier::whereKey($data['supplier_id'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->firstOrFail();
        $list = !empty($data['price_list_id'])
            ? PriceList::where('company_id', $companyId)->where('list_type', 'purchase')->findOrFail($data['price_list_id'])
            : null;
        if ($list && !$list->is_active) abort(422, 'An inactive purchase price list cannot be assigned.');
        $before = ['purchase_price_list_id' => $supplier->purchase_price_list_id];
        $supplier->update(['purchase_price_list_id' => $list?->id]);
        app(AuditService::class)->record('supplier.purchase_price_list_updated', $supplier, $before, $supplier->fresh()->only(['purchase_price_list_id']));
        return response()->json(['data' => $supplier->fresh('purchasePriceList'), 'status' => 'updated']);
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
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $prices = SupplierProductPrice::query()
            ->with(['supplier:id,name', 'product:id,name,sku'])
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->where('is_active', true)
            ->where('approval_status', $data['approval_status'] ?? 'approved')
            ->when($data['currency_code'] ?? null, fn ($query, $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when($data['quantity'] ?? null, fn ($query, $quantity) => $query->where('minimum_quantity', '<=', $quantity))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($prices, $request, 'pricing.supplier-prices', 100);
    }

    public function storeSupplierPrice(Request $request): JsonResponse
    {
        $this->assertAccess($request, 'purchase');
        $companyId = $request->user()?->company_id;
        $data = $this->supplierPriceData($request, $companyId);
        $data['currency_code'] = strtoupper($data['currency_code']);
        if (!empty($data['external_reference'])) {
            $existing = SupplierProductPrice::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['supplier', 'product']), 'status' => 'duplicate_ignored']);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if (!$supplier->is_active) abort(422, 'The selected supplier is inactive.');
        $price = DB::transaction(function () use ($data, $companyId, $request): SupplierProductPrice {
            $price = SupplierProductPrice::create($data + ['company_id' => $companyId, 'is_active' => true, 'approval_status' => $this->requiresSupplierPriceApproval($companyId) ? 'pending' : 'approved', 'created_by' => $request->user()?->id]);
            app(AuditService::class)->record('supplier_product_price.created', $price, null, $price->toArray());
            return $price;
        });
        return response()->json(['data' => $price->load(['supplier', 'product']), 'status' => $price->approval_status === 'pending' ? 'pending_approval' : 'created'], 201);
    }

    public function updateSupplierPrice(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'purchase');
        $companyId = $request->user()?->company_id;
        $price = SupplierProductPrice::findOrFail($id);
        $data = $this->supplierPriceData($request, $companyId, true);
        if (array_key_exists('supplier_id', $data)) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            if (!$supplier->is_active) abort(422, 'The selected supplier is inactive.');
        }
        if (array_key_exists('external_reference', $data) && $data['external_reference'] !== $price->external_reference && $data['external_reference'] !== null) {
            $duplicate = SupplierProductPrice::where('external_reference', $data['external_reference'])->where('id', '<>', $price->id)->exists();
            if ($duplicate) abort(422, 'The external reference is already assigned to another supplier price.');
        }
        $before = $price->only(array_keys($data));
        $approval = $this->requiresSupplierPriceApproval($companyId) && ($price->approval_status ?? 'approved') === 'approved'
            ? ['approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]
            : [];
        $price->update(array_merge($data, isset($data['currency_code']) ? ['currency_code' => strtoupper($data['currency_code'])] : [], $approval));
        app(AuditService::class)->record('supplier_product_price.updated', $price, $before, $price->fresh()->only(array_keys($data)));
        return response()->json(['data' => $price->fresh()->load(['supplier', 'product']), 'status' => 'updated']);
    }

    public function deactivateSupplierPrice(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'purchase');
        $price = SupplierProductPrice::findOrFail($id);
        if ($price->is_active) {
            $price->update(['is_active' => false]);
            app(AuditService::class)->record('supplier_product_price.deactivated', $price, ['is_active' => true], ['is_active' => false]);
        }
        return response()->json(['data' => $price->fresh()->load(['supplier', 'product']), 'status' => 'deactivated']);
    }

    public function approveSupplierPrice(int $id): JsonResponse
    {
        $price = SupplierProductPrice::findOrFail($id);
        if (($price->approval_status ?? 'approved') === 'approved') return response()->json(['data' => $price, 'status' => 'already_approved']);
        try {
            app(\App\Services\ApprovalGuard::class)->assertDifferent($price);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $before = $price->only(['approval_status', 'approved_by', 'approved_at', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $price->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]);
        app(AuditService::class)->record('supplier_product_price.approved', $price, $before, $price->fresh()->only(array_keys($before)));
        return response()->json(['data' => $price->fresh()->load(['supplier', 'product']), 'status' => 'approved']);
    }

    public function rejectSupplierPrice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $price = SupplierProductPrice::findOrFail($id);
        if (($price->approval_status ?? 'approved') === 'approved') abort(422, 'An approved supplier price must be updated to create a new pending version.');
        if ($price->created_by && (int) $price->created_by === (int) auth()->id()) abort(422, 'The supplier-price creator cannot reject the same agreement.');
        $before = $price->only(['approval_status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $price->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('supplier_product_price.rejected', $price, $before, $price->fresh()->only(array_keys($before)));
        return response()->json(['data' => $price->fresh()->load(['supplier', 'product']), 'status' => 'rejected']);
    }

    public function compareSupplierPrices(int $id, int $otherId): JsonResponse
    {
        $left = SupplierProductPrice::with(['supplier:id,name', 'product:id,name,sku'])->findOrFail($id);
        $right = SupplierProductPrice::with(['supplier:id,name', 'product:id,name,sku'])->findOrFail($otherId);
        if ((int) $left->supplier_id !== (int) $right->supplier_id || (int) $left->product_id !== (int) $right->product_id) abort(422, 'Supplier-price versions can only be compared for the same supplier and product.');
        $fields = ['minimum_quantity', 'unit_price', 'currency_code', 'starts_on', 'ends_on', 'supplier_sku', 'lead_time_days', 'is_active', 'approval_status', 'external_reference'];
        $snapshot = function (SupplierProductPrice $price) use ($fields): array {
            $values = ['id' => $price->id, 'supplier_id' => $price->supplier_id, 'product_id' => $price->product_id];
            foreach ($fields as $field) $values[$field] = in_array($field, ['starts_on', 'ends_on'], true) ? optional($price->{$field})->toDateString() : ($field === 'is_active' ? (bool) $price->{$field} : ($field === 'approval_status' ? ($price->{$field} ?? 'approved') : $price->{$field}));
            return $values;
        };
        $from = $snapshot($left); $to = $snapshot($right); $changes = [];
        foreach ($fields as $field) if ((string) $from[$field] !== (string) $to[$field]) $changes[$field] = ['from' => $from[$field], 'to' => $to[$field]];
        return response()->json(['data' => ['from' => $from, 'to' => $to, 'changes' => $changes]]);
    }

    public function importSupplierPrices(Request $request, ProductSpreadsheetService $spreadsheet, SupplierProductPriceImportService $imports): JsonResponse
    {
        $this->assertAccess($request, 'purchase');
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for supplier-price imports.');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:51200'], 'dry_run' => ['nullable', 'boolean']]);
        try {
            $extension = strtolower($request->file('file')->getClientOriginalExtension());
            $rows = $spreadsheet->read($request->file('file')->getRealPath(), $extension);
            $validated = $imports->validateRows($rows, $companyId);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($validated['errors']) return response()->json(['message' => 'Supplier-price import validation failed.', 'errors' => $validated['errors']], 422);
        if ((bool) ($data['dry_run'] ?? false)) return response()->json(['status' => 'dry_run', 'rows' => count($validated['rows']), 'imported' => 0]);
        $imported = $imports->importRows($validated['rows'], $companyId, $request->user()?->id);
        return response()->json(['status' => 'imported', 'rows' => count($validated['rows']), 'imported' => $imported], 201);
    }

    private function supplierPriceData(Request $request, ?int $companyId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        return $request->validate([
            'supplier_id' => [$required, 'integer', $owned('suppliers')], 'product_id' => [$required, 'integer', $owned('products')],
            'minimum_quantity' => [$required, 'numeric', 'gt:0'], 'unit_price' => [$required, 'numeric', 'min:0'],
            'currency_code' => [$required, 'string', 'size:3'], 'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'], 'supplier_sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0'], 'external_reference' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:150'],
        ]);
    }

    private function requiresSupplierPriceApproval(?int $companyId): bool
    {
        return ApprovalPolicy::withoutGlobalScopes()->where('company_id', $companyId)->where('document_type', SupplierProductPrice::class)->where('is_active', true)->exists();
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

    public function storeCustomerPrice(Request $request): JsonResponse
    {
        $this->assertSalesWrite($request);
        $companyId = $request->user()?->company_id;
        if ($request->filled('external_reference')) {
            $existing = CustomerProductPrice::where('company_id', $companyId)->where('external_reference', $request->input('external_reference'))->first();
            if ($existing) return response()->json(['data' => $existing->load(['customer', 'product']), 'status' => 'duplicate_ignored']);
        }
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', $owned('customers')],
            'customer_group' => ['nullable', 'string', 'max:100'], 'sales_channel' => ['nullable', 'string', 'max:50'],
            'product_id' => ['required', 'integer', $owned('products')], 'minimum_quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'], 'currency_code' => ['required', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        if (empty($data['customer_id']) && empty($data['customer_group']) && empty($data['sales_channel'])) abort(422, 'A customer, customer group, or sales channel is required.');
        $price = DB::transaction(function () use ($data, $companyId, $request): CustomerProductPrice {
            $price = CustomerProductPrice::create(array_merge($data, ['company_id' => $companyId, 'currency_code' => strtoupper($data['currency_code']), 'discount_percent' => $data['discount_percent'] ?? 0, 'is_active' => true]));
            app(AuditService::class)->record('customer_product_price.created', $price, null, $price->toArray());
            return $price;
        });
        return response()->json(['data' => $price->load(['customer', 'product']), 'status' => 'created'], 201);
    }

    public function deactivateCustomerPrice(Request $request, int $id): JsonResponse
    {
        $this->assertSalesWrite($request);
        $price = CustomerProductPrice::where('company_id', $request->user()?->company_id)->findOrFail($id);
        if ($price->is_active) {
            $before = $price->only(['is_active']);
            $price->update(['is_active' => false]);
            app(AuditService::class)->record('customer_product_price.deactivated', $price, $before, $price->fresh()->only(['is_active']));
        }
        return response()->json(['data' => $price->fresh()->load(['customer', 'product']), 'status' => 'deactivated']);
    }

    public function updateCustomerPrice(Request $request, int $id): JsonResponse
    {
        $this->assertSalesWrite($request);
        $companyId = $request->user()?->company_id;
        $price = CustomerProductPrice::where('company_id', $companyId)->findOrFail($id);
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'integer', $owned('customers')],
            'customer_group' => ['sometimes', 'nullable', 'string', 'max:100'], 'sales_channel' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_id' => ['sometimes', 'integer', $owned('products')], 'minimum_quantity' => ['sometimes', 'numeric', 'gt:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'], 'currency_code' => ['sometimes', 'string', 'size:3'],
            'starts_on' => ['sometimes', 'nullable', 'date'], 'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('customer_product_prices', 'external_reference')->ignore($price->id)->where(fn ($query) => $query->where('company_id', $companyId))],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $scope = [
            'customer_id' => array_key_exists('customer_id', $data) ? $data['customer_id'] : $price->customer_id,
            'customer_group' => array_key_exists('customer_group', $data) ? $data['customer_group'] : $price->customer_group,
            'sales_channel' => array_key_exists('sales_channel', $data) ? $data['sales_channel'] : $price->sales_channel,
        ];
        if (empty($scope['customer_id']) && empty($scope['customer_group']) && empty($scope['sales_channel'])) abort(422, 'A customer, customer group, or sales channel is required.');
        $before = $price->only(array_keys($data));
        if (isset($data['currency_code'])) $data['currency_code'] = strtoupper($data['currency_code']);
        $price->update($data);
        app(AuditService::class)->record('customer_product_price.updated', $price, $before, $price->fresh()->only(array_keys($data)));
        return response()->json(['data' => $price->fresh()->load(['customer', 'product']), 'status' => 'updated']);
    }
}
