<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    public function index()
    {
        $lists = PriceList::withCount('items')->latest()->paginate(30);
        $products = Product::where('status', 1)->orderBy('name')->get(['id', 'name', 'sku']);
        $customers = Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'sales_price_list_id']);
        $suppliers = Supplier::where('status', 1)->orderBy('name')->get(['id', 'name', 'purchase_price_list_id']);
        return view('admin.erp.price_lists', compact('lists', 'products', 'customers', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'list_type' => ['required', 'in:sales,purchase'], 'currency_code' => ['required', 'string', 'size:3'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on']]);
        $companyId = auth()->user()?->company_id;
        if (!$companyId) abort(422, 'A company is required for price lists.');
        if (PriceList::where('company_id', $companyId)->where('name', $data['name'])->exists()) return back()->withErrors(['name' => 'This price-list name already exists.'])->withInput();
        $list = PriceList::create($data + ['company_id' => $companyId, 'currency_code' => strtoupper($data['currency_code']), 'is_active' => true]);
        app(AuditService::class)->record('price_list.created', $list, null, $list->toArray());
        return back()->with(['message' => 'Price list created.', 'alert-type' => 'success']);
    }

    public function storeItem(Request $request)
    {
        $data = $request->validate(['price_list_id' => ['required', 'integer', 'exists:price_lists,id'], 'product_id' => ['required', 'integer', 'exists:products,id'], 'minimum_quantity' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'numeric', 'min:0'], 'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $list = PriceList::findOrFail($data['price_list_id']);
        if (!$list->is_active) return back()->withErrors(['price_list_id' => 'Items cannot be added to an inactive price list.'])->withInput();
        if (!Product::whereKey($data['product_id'])->exists()) abort(403, 'The product is outside the current company.');
        if (PriceListItem::where('price_list_id', $list->id)->where('product_id', $data['product_id'])->where('minimum_quantity', $data['minimum_quantity'])->exists()) return back()->withErrors(['minimum_quantity' => 'This product quantity break already exists.'])->withInput();
        $item = PriceListItem::create($data + ['company_id' => $list->company_id, 'price_list_id' => $list->id, 'discount_percent' => $data['discount_percent'] ?? 0, 'is_active' => true]);
        app(AuditService::class)->record('price_list_item.created', $item, null, $item->toArray());
        return back()->with(['message' => 'Price-list item saved.', 'alert-type' => 'success']);
    }

    public function assignCustomer(Request $request)
    {
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:customers,id'], 'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id']]);
        $customer = Customer::findOrFail($data['customer_id']);
        if ($data['price_list_id'] && !PriceList::whereKey($data['price_list_id'])->where('list_type', 'sales')->where('is_active', true)->exists()) abort(422, 'Select an active sales price list.');
        $before = ['sales_price_list_id' => $customer->sales_price_list_id];
        $customer->update(['sales_price_list_id' => $data['price_list_id'] ?: null]);
        app(AuditService::class)->record('customer.sales_price_list_updated', $customer, $before, $customer->fresh()->only(['sales_price_list_id']));
        return back()->with(['message' => 'Customer price list updated.', 'alert-type' => 'success']);
    }

    public function assignSupplier(Request $request)
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id']]);
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if ($data['price_list_id'] && !PriceList::whereKey($data['price_list_id'])->where('list_type', 'purchase')->where('is_active', true)->exists()) abort(422, 'Select an active purchase price list.');
        $before = ['purchase_price_list_id' => $supplier->purchase_price_list_id];
        $supplier->update(['purchase_price_list_id' => $data['price_list_id'] ?: null]);
        app(AuditService::class)->record('supplier.purchase_price_list_updated', $supplier, $before, $supplier->fresh()->only(['purchase_price_list_id']));
        return back()->with(['message' => 'Supplier price list updated.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $list = PriceList::findOrFail($id);
        if ($list->is_active) {
            $list->update(['is_active' => false]);
            app(AuditService::class)->record('price_list.deactivated', $list, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Price list deactivated.', 'alert-type' => 'success']);
    }
}
