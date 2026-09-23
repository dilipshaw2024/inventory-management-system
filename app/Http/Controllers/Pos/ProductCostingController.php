<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\ProductCostingPolicyService;
use Carbon\CarbonImmutable;
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
        $data = $request->validate(['costing_method' => ['required', 'in:fifo,weighted_average,moving_average,standard'], 'standard_cost' => ['nullable', 'numeric', 'min:0'], 'effective_at' => ['nullable', 'date', 'after:now']]);
        $product = Product::where('company_id', $this->companyId())->findOrFail($id);
        if (($data['costing_method'] ?? null) === 'standard' && (($data['standard_cost'] ?? null) === null)) return back()->withInput()->withErrors(['standard_cost' => 'Standard costing requires a standard cost.']);
        $old = $product->only(['costing_method', 'standard_cost']);
        if (!empty($data['effective_at'])) {
            app(ProductCostingPolicyService::class)->schedule($product, $data['costing_method'], isset($data['standard_cost']) ? (float) $data['standard_cost'] : null, CarbonImmutable::parse($data['effective_at']), auth()->id(), 'Scheduled from costing administration.');
            app(AuditService::class)->record('product.costing.scheduled', $product, null, ['costing_method' => $data['costing_method'], 'standard_cost' => $data['standard_cost'] ?? null, 'effective_at' => $data['effective_at']]);
            return back()->with(['message' => 'Product costing policy scheduled.', 'alert-type' => 'success']);
        }
        $product->update(['costing_method' => $data['costing_method'], 'standard_cost' => $data['standard_cost'] ?? null]);
        app(ProductCostingPolicyService::class)->recordCurrent($product, $product->costing_method, $product->standard_cost !== null ? (float) $product->standard_cost : null, auth()->id(), 'Updated from costing administration.');
        app(AuditService::class)->record('product.costing.updated', $product, $old, $product->only(['costing_method', 'standard_cost']));
        return back()->with(['message' => 'Product costing method updated.', 'alert-type' => 'success']);
    }
}
