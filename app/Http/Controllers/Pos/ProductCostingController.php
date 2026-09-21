<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ProductCostingController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function index()
    {
        $products = Product::where('company_id', $this->companyId())->orderBy('name')->paginate(50);
        return view('backend.product.costing_methods', compact('products'));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['costing_method' => ['required', 'in:fifo,weighted_average,moving_average,standard'], 'standard_cost' => ['nullable', 'numeric', 'min:0']]);
        $product = Product::where('company_id', $this->companyId())->findOrFail($id); $old = $product->only(['costing_method', 'standard_cost']);
        $product->update($data);
        ProductCostHistory::create(['product_id' => $product->id, 'old_costing_method' => $old['costing_method'], 'new_costing_method' => $product->costing_method, 'old_standard_cost' => $old['standard_cost'], 'new_standard_cost' => $product->standard_cost, 'effective_at' => now(), 'changed_by' => auth()->id()]);
        app(AuditService::class)->record('product.costing.updated', $product, $old, $product->only(['costing_method', 'standard_cost']));
        return back()->with(['message' => 'Product costing method updated.', 'alert-type' => 'success']);
    }
}
