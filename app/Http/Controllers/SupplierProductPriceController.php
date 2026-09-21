<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ApprovalPolicy;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use App\Services\AuditService;
use App\Services\ApprovalGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierProductPriceController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for supplier pricing.');
        $owned = fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id');
        $prices = SupplierProductPrice::with(['supplier', 'product'])->where('company_id', $companyId)->latest()->paginate(40);
        $suppliers = Supplier::where('is_active', true)->where($owned)->orderBy('name')->get();
        $products = Product::where('status', 1)->where($owned)->orderBy('name')->get();
        return view('backend.purchase.supplier_prices', compact('prices', 'suppliers', 'products'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for supplier pricing.');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'minimum_quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
        ]);
        if (!Supplier::whereKey($data['supplier_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('is_active', true)->exists() || !Product::whereKey($data['product_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists()) {
            abort(403, 'The supplier and product must belong to the current company.');
        }
        $data['currency_code'] = strtoupper($data['currency_code']);
        $price = SupplierProductPrice::create($data + ['company_id' => $companyId, 'is_active' => true, 'approval_status' => $this->requiresApproval() ? 'pending' : 'approved', 'created_by' => auth()->id()]);
        app(AuditService::class)->record('supplier_product_price.created', $price, null, $price->toArray());
        return back()->with(['message' => $price->approval_status === 'pending' ? 'Supplier price agreement submitted for approval.' : 'Supplier price agreement saved.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $price = SupplierProductPrice::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if (!$price->is_active) return back()->with(['message' => 'Supplier price agreement is already inactive.', 'alert-type' => 'error']);
        $before = $price->only(['is_active']);
        $price->update(['is_active' => false]);
        app(AuditService::class)->record('supplier_product_price.deactivated', $price, $before, $price->fresh()->only(['is_active']));
        return back()->with(['message' => 'Supplier price agreement deactivated.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        $price = SupplierProductPrice::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if (($price->approval_status ?? 'approved') === 'approved') return back()->with(['message' => 'Supplier price agreement is already approved.', 'alert-type' => 'success']);
        try {
            app(ApprovalGuard::class)->assertDifferent($price);
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        $price->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]);
        app(AuditService::class)->record('supplier_product_price.approved', $price, ['approval_status' => 'pending'], ['approval_status' => 'approved']);
        return back()->with(['message' => 'Supplier price agreement approved.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $price = SupplierProductPrice::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if (($price->approval_status ?? 'approved') === 'approved') return back()->with(['message' => 'An approved agreement must be updated to create a new pending version.', 'alert-type' => 'error']);
        if ($price->created_by && (int) $price->created_by === (int) auth()->id()) return back()->with(['message' => 'The supplier-price creator cannot reject the same agreement.', 'alert-type' => 'error']);
        $price->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('supplier_product_price.rejected', $price, ['approval_status' => 'pending'], ['approval_status' => 'rejected', 'rejection_reason' => $data['reason']]);
        return back()->with(['message' => 'Supplier price agreement rejected.', 'alert-type' => 'success']);
    }

    private function requiresApproval(): bool
    {
        return ApprovalPolicy::withoutGlobalScopes()->where('company_id', auth()->user()?->company_id)->where('document_type', SupplierProductPrice::class)->where('is_active', true)->exists();
    }
}
