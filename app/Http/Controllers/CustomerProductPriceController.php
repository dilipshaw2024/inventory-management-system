<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CustomerProductPriceController extends Controller
{
    public function index()
    {
        $prices = CustomerProductPrice::with(['customer', 'product'])->latest()->paginate(40);
        $customers = Customer::where('status', 1)->orderBy('name')->get();
        $products = Product::where('status', 1)->orderBy('name')->get();
        return view('backend.invoice.customer_prices', compact('prices', 'customers', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_group' => ['nullable', 'string', 'max:100'],
            'sales_channel' => ['nullable', 'string', 'max:50'],
            'product_id' => ['required', 'exists:products,id'],
            'minimum_quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (empty($data['customer_id']) && empty($data['customer_group']) && empty($data['sales_channel'])) return back()->withErrors(['customer_id' => 'Select a customer or provide a customer group/sales channel price scope.'])->withInput();
        if (auth()->user()?->company_id && ((!empty($data['customer_id']) && !Customer::whereKey($data['customer_id'])->exists()) || !Product::whereKey($data['product_id'])->exists())) {
            abort(403, 'The customer and product must belong to the current company.');
        }
        $price = CustomerProductPrice::create($data + ['currency_code' => strtoupper($data['currency_code']), 'discount_percent' => $data['discount_percent'] ?? 0, 'is_active' => true]);
        app(AuditService::class)->record('customer_product_price.created', $price, null, $price->toArray());
        return back()->with(['message' => 'Customer price agreement saved.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $price = CustomerProductPrice::findOrFail($id);
        if (!$price->is_active) return back()->with(['message' => 'Customer price agreement is already inactive.', 'alert-type' => 'error']);
        $before = $price->only(['is_active']);
        $price->update(['is_active' => false]);
        app(AuditService::class)->record('customer_product_price.deactivated', $price, $before, $price->fresh()->only(['is_active']));
        return back()->with(['message' => 'Customer price agreement deactivated.', 'alert-type' => 'success']);
    }
}
