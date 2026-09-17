<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\ApprovalGuard;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseRequisitionController extends Controller
{
    private function companyExists(string $table) { return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id')); }

    public function index()
    {
        $requisitions = PurchaseRequisition::with(['supplier', 'lines.product'])->latest()->paginate(30);
        return view('backend.purchase.requisitions', compact('requisitions'));
    }

    public function create()
    {
        $products = Product::where('status', 1)->orderBy('name')->get();
        $suppliers = Supplier::where('status', 1)->orderBy('name')->get();
        return view('backend.purchase.requisition_add', compact('products', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'requisition_no' => ['nullable', 'string', 'max:100', 'unique:purchase_requisitions,requisition_no'],
            'requested_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date', 'after_or_equal:requested_date'],
            'suggested_supplier_id' => ['nullable', $this->companyExists('suppliers')],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', $this->companyExists('products')],
            'requested_qty' => ['required', 'array', 'min:1'],
            'requested_qty.*' => ['required', 'numeric', 'gt:0'],
            'estimated_unit_price' => ['required', 'array', 'min:1'],
            'estimated_unit_price.*' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:500'],
        ]);
        $requisition = PurchaseRequisition::create([
            'company_id' => auth()->user()?->company_id,
            'requisition_no' => $data['requisition_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('purchase_requisition', 'PR-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
            'requested_date' => $data['requested_date'],
            'required_date' => $data['required_date'] ?? null,
            'suggested_supplier_id' => $data['suggested_supplier_id'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by' => auth()->id(),
            'status' => 'submitted',
        ]);
        foreach ($data['product_id'] as $index => $productId) {
            PurchaseRequisitionLine::create([
                'purchase_requisition_id' => $requisition->id,
                'product_id' => $productId,
                'requested_qty' => $data['requested_qty'][$index],
                'estimated_unit_price' => $data['estimated_unit_price'][$index],
                'notes' => $data['notes'][$index] ?? null,
            ]);
        }
        app(AuditService::class)->record('purchase_requisition.created', $requisition, null, $requisition->toArray());
        return redirect()->route('procurement.requisitions.index')->with(['message' => 'Purchase requisition submitted.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(PurchaseRequisition::class, $id);
        $requisition = PurchaseRequisition::findOrFail($id);
        if ($requisition->status !== 'submitted') return back()->with(['message' => 'Only submitted requisitions can be approved.', 'alert-type' => 'error']);
        try { app(ApprovalGuard::class)->assertDifferent($requisition); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $requisition->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        app(AuditService::class)->record('purchase_requisition.approved', $requisition, ['status' => 'submitted'], ['status' => 'approved']);
        return back()->with(['message' => 'Purchase requisition approved.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $requisition = PurchaseRequisition::findOrFail($id);
            if ($requisition->status !== 'submitted') throw new \RuntimeException('Only submitted requisitions can be rejected.');
            app(ApprovalGuard::class)->assertDifferent($requisition);
            $before = $requisition->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $requisition->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('purchase_requisition.rejected', $requisition, $before, $requisition->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'Purchase requisition rejected.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function convert(int $id)
    {
        $requisition = PurchaseRequisition::with('lines')->findOrFail($id);
        if ($requisition->status !== 'approved') return back()->with(['message' => 'Only approved requisitions can be converted.', 'alert-type' => 'error']);
        if (!$requisition->suggested_supplier_id) return back()->with(['message' => 'Select a suggested supplier before converting this requisition.', 'alert-type' => 'error']);
        DB::transaction(function () use ($requisition): void {
            $order = PurchaseOrder::create([
                'company_id' => $requisition->company_id ?: auth()->user()?->company_id,
                'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                'supplier_id' => $requisition->suggested_supplier_id, 'date' => now()->toDateString(), 'expected_date' => $requisition->required_date,
                'description' => 'Converted from requisition '.$requisition->requisition_no, 'status' => 'submitted', 'created_by' => auth()->id(),
            ]);
            foreach ($requisition->lines as $line) PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->requested_qty, 'unit_price' => $line->estimated_unit_price]);
            $requisition->update(['status' => 'converted']);
            app(AuditService::class)->record('purchase_requisition.converted', $requisition, ['status' => 'approved'], ['status' => 'converted', 'purchase_order_id' => $order->id]);
        });
        return redirect()->route('procurement.orders')->with(['message' => 'Requisition converted into a multi-line purchase order.', 'alert-type' => 'success']);
    }
}
