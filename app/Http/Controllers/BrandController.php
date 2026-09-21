<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function index()
    {
        $brands = Brand::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->withCount('products')->orderBy('name')->paginate(30);
        return view('backend.product.brands', compact('brands'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'code' => ['required', 'string', 'max:50', Rule::unique('brands', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'is_active' => ['nullable', 'boolean']]);
        $brand = Brand::create($data + ['company_id' => $this->companyId(), 'is_active' => $request->boolean('is_active', true)]);
        app(AuditService::class)->record('brand.created', $brand, null, $brand->toArray());
        return back()->with(['message' => 'Brand created.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $brand = Brand::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'code' => ['required', 'string', 'max:50', Rule::unique('brands', 'code')->ignore($brand->id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'is_active' => ['nullable', 'boolean']]);
        $old = $brand->toArray();
        $brand->update($data + ['is_active' => $request->boolean('is_active', false)]);
        app(AuditService::class)->record('brand.updated', $brand, $old, $brand->fresh()->toArray());
        return back()->with(['message' => 'Brand updated.', 'alert-type' => 'success']);
    }

    public function destroy(int $id)
    {
        $brand = Brand::where('company_id', $this->companyId())->findOrFail($id);
        if ($brand->products()->exists()) return back()->with(['message' => 'A brand linked to products cannot be deleted.', 'alert-type' => 'error']);
        $brand->delete();
        app(AuditService::class)->record('brand.deleted', $brand, null, ['deleted' => true]);
        return back()->with(['message' => 'Brand deleted.', 'alert-type' => 'success']);
    }
}
