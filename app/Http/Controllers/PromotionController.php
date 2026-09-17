<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::with(['product', 'category', 'customer'])->latest()->paginate(30);
        return view('backend.sales.promotions', compact('promotions'));
    }

    public function create()
    {
        return view('backend.sales.promotion_add', ['products' => Product::where('status', 1)->orderBy('name')->get(), 'categories' => Category::orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['code' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('promotions', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:150'], 'type' => ['required', 'in:percentage,fixed,bogo'], 'discount_value' => ['nullable', 'numeric', 'min:0'], 'buy_quantity' => ['nullable', 'numeric', 'gt:0'], 'get_quantity' => ['nullable', 'numeric', 'gt:0'], 'minimum_quantity' => ['nullable', 'numeric', 'min:0'], 'usage_limit' => ['nullable', 'integer', 'min:1'], 'stackable' => ['sometimes', 'boolean'], 'customer_id' => ['nullable', 'exists:customers,id'], 'product_id' => ['nullable', 'exists:products,id'], 'category_id' => ['nullable', 'exists:categories,id'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on']]);
        if ($data['type'] !== 'bogo' && (float) ($data['discount_value'] ?? 0) <= 0) return back()->withErrors(['discount_value' => 'A percentage or fixed promotion requires a positive discount value.'])->withInput();
        if ($data['type'] === 'bogo' && (empty($data['buy_quantity']) || empty($data['get_quantity']))) return back()->withErrors(['buy_quantity' => 'BOGO promotions require buy and get quantities.'])->withInput();
        if ($data['type'] === 'bogo') $data['discount_value'] = $data['discount_value'] ?? 0;
        if ($data['type'] === 'percentage' && (float) $data['discount_value'] > 100) return back()->withErrors(['discount_value' => 'Percentage discount cannot exceed 100.'])->withInput();
        if (auth()->user()?->company_id && ((!empty($data['customer_id']) && !Customer::whereKey($data['customer_id'])->exists()) || (!empty($data['product_id']) && !Product::whereKey($data['product_id'])->exists()) || (!empty($data['category_id']) && !Category::whereKey($data['category_id'])->exists()))) {
            abort(403, 'Promotion references must belong to the current company.');
        }
        $promotion = Promotion::create(array_merge($data, ['company_id' => $companyId, 'code' => strtoupper($data['code']), 'is_active' => true, 'created_by' => auth()->id()]));
        app(AuditService::class)->record('promotion.created', $promotion, null, $promotion->toArray());
        return redirect()->route('sales.promotions.index')->with(['message' => 'Promotion created.', 'alert-type' => 'success']);
    }
}
