<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductUomController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()?->company_id;
        $productScope = fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id');
        $uoms = ProductUom::with(['product', 'unit'])->whereHas('product', $productScope)->latest()->paginate(50);
        $products = Product::where('status', 1)->where($productScope)->orderBy('name')->get();
        $units = Unit::where('status', 1)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('name')->get();
        return view('backend.product.product_uoms', compact('uoms', 'products', 'units'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $unitScope = Rule::exists('units', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'product_id' => ['required', 'integer', $productScope], 'unit_id' => ['required', 'integer', $unitScope],
            'conversion_to_stock' => ['required', 'numeric', 'gt:0'], 'usage' => ['required', 'in:purchase,sales,both'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:8'],
        ]);
        $uom = ProductUom::updateOrCreate(['product_id' => $data['product_id'], 'unit_id' => $data['unit_id']], $data + ['is_active' => true]);
        app(AuditService::class)->record('product_uom.updated', $uom, null, $uom->toArray());
        return back()->with(['message' => 'Product UOM conversion saved.', 'alert-type' => 'success']);
    }

    public function destroy(int $id)
    {
        $companyId = auth()->user()?->company_id;
        $uom = ProductUom::whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($id);
        $uom->update(['is_active' => false]);
        app(AuditService::class)->record('product_uom.deactivated', $uom, ['is_active' => true], ['is_active' => false]);
        return back()->with(['message' => 'Product UOM deactivated.', 'alert-type' => 'success']);
    }
}
