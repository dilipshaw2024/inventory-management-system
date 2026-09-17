<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SupplierProductPriceController extends Controller
{
    public function index()
    {
        $prices = SupplierProductPrice::with(['supplier', 'product'])->latest()->paginate(40);
        $suppliers = Supplier::where('status', 1)->orderBy('name')->get();
        $products = Product::where('status', 1)->orderBy('name')->get();
        return view('backend.purchase.supplier_prices', compact('prices', 'suppliers', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'product_id' => ['required', 'exists:products,id'],
            'minimum_quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
        ]);
        if (auth()->user()?->company_id && (!Supplier::whereKey($data['supplier_id'])->exists() || !Product::whereKey($data['product_id'])->exists())) {
            abort(403, 'The supplier and product must belong to the current company.');
        }
        $price = SupplierProductPrice::create($data + ['currency_code' => strtoupper($data['currency_code']), 'is_active' => true]);
        app(AuditService::class)->record('supplier_product_price.created', $price, null, $price->toArray());
        return back()->with(['message' => 'Supplier price agreement saved.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $price = SupplierProductPrice::findOrFail($id);
        if (!$price->is_active) return back()->with(['message' => 'Supplier price agreement is already inactive.', 'alert-type' => 'error']);
        $before = $price->only(['is_active']);
        $price->update(['is_active' => false]);
        app(AuditService::class)->record('supplier_product_price.deactivated', $price, $before, $price->fresh()->only(['is_active']));
        return back()->with(['message' => 'Supplier price agreement deactivated.', 'alert-type' => 'success']);
    }
}
