<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqLine;
use App\Models\PurchaseRfqSupplier;
use App\Models\PurchaseSupplierQuotation;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseRfqController extends Controller
{
    private function companyExists(string $table) { return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id')); }

    public function index()
    {
        $rfqs = PurchaseRfq::where('company_id', auth()->user()?->company_id)->with(['lines.product', 'suppliers.supplier', 'suppliers.quotations'])->latest()->paginate(30);
        return view('backend.purchase.rfqs', compact('rfqs'));
    }

    public function create()
    {
        return view('backend.purchase.rfq_add', [
            'products' => Product::where('status', 1)->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 1)->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rfq_no' => ['nullable', 'string', 'max:100', Rule::unique('purchase_rfqs', 'rfq_no')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))],
            'issue_date' => ['required', 'date'],
            'response_due' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'supplier_id' => ['required', 'array', 'min:1'],
            'supplier_id.*' => ['required', 'distinct', $this->companyExists('suppliers')],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', $this->companyExists('products')],
            'requested_qty' => ['required', 'array', 'min:1'],
            'requested_qty.*' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($data, &$rfq): void {
            $rfq = PurchaseRfq::create([
                'company_id' => auth()->user()?->company_id,
                'rfq_no' => $data['rfq_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('purchase_rfq', 'RFQ-'.now()->format('YmdHisv').'-'.Str::uuid()->toString(), auth()->user()?->company_id, auth()->user()?->branch_id),
                'issue_date' => $data['issue_date'], 'response_due' => $data['response_due'] ?? null,
                'description' => $data['description'] ?? null, 'status' => 'submitted', 'created_by' => auth()->id(),
            ]);
            foreach ($data['supplier_id'] as $supplierId) PurchaseRfqSupplier::create(['purchase_rfq_id' => $rfq->id, 'supplier_id' => $supplierId]);
            foreach ($data['product_id'] as $index => $productId) PurchaseRfqLine::create(['purchase_rfq_id' => $rfq->id, 'product_id' => $productId, 'requested_qty' => $data['requested_qty'][$index], 'notes' => $data['notes'][$index] ?? null]);
            app(AuditService::class)->record('purchase_rfq.created', $rfq, null, $rfq->toArray());
        });
        return redirect()->route('procurement.rfqs.index')->with(['message' => 'RFQ issued to selected suppliers.', 'alert-type' => 'success']);
    }

    public function quoteForm(int $id, int $supplierId)
    {
        $rfq = PurchaseRfq::where('company_id', auth()->user()?->company_id)->with('lines.product')->findOrFail($id);
        $supplier = PurchaseRfqSupplier::with('supplier')->where('purchase_rfq_id', $rfq->id)->findOrFail($supplierId);
        return view('backend.purchase.rfq_quote', compact('rfq', 'supplier'));
    }

    public function compare(int $id)
    {
        $rfq = PurchaseRfq::where('company_id', auth()->user()?->company_id)->with(['lines.product', 'suppliers.supplier', 'suppliers.quotations'])->findOrFail($id);
        $comparison = $rfq->lines->map(function (PurchaseRfqLine $line) use ($rfq): array {
            $quotes = $rfq->suppliers->flatMap(function (PurchaseRfqSupplier $response) use ($line) {
                return $response->quotations->where('purchase_rfq_line_id', $line->id)->map(fn (PurchaseSupplierQuotation $quote) => ['supplier' => $response->supplier->name, 'unit_price' => (float) $quote->unit_price, 'lead_days' => $quote->lead_days, 'valid_until' => $quote->valid_until]);
            })->sortBy('unit_price')->values();
            return ['line' => $line, 'quotes' => $quotes, 'best' => $quotes->first()];
        });
        return view('backend.purchase.rfq_compare', compact('rfq', 'comparison'));
    }

    public function award(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['rfq_supplier_id' => ['required', 'integer', 'exists:purchase_rfq_suppliers,id']]);
        $rfq = PurchaseRfq::where('company_id', $companyId)->with('lines')->findOrFail($id);
        if ($rfq->status !== 'submitted') return back()->with(['message' => 'Only submitted RFQs can be awarded.', 'alert-type' => 'error']);
        $response = PurchaseRfqSupplier::with('quotations')->where('purchase_rfq_id', $rfq->id)->findOrFail($data['rfq_supplier_id']);
        $quotes = $response->quotations->keyBy('purchase_rfq_line_id');
        if ($rfq->lines->contains(fn (PurchaseRfqLine $line): bool => !$quotes->has($line->id))) return back()->with(['message' => 'The selected supplier must quote every RFQ line before award.', 'alert-type' => 'error']);

        DB::transaction(function () use ($rfq, $response, $quotes, &$order): void {
            $leadDays = (int) ($response->quotations->max('lead_days') ?: 0);
            $order = \App\Models\PurchaseOrder::create([
                'company_id' => $rfq->company_id ?: auth()->user()?->company_id,
                'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                'supplier_id' => $response->supplier_id, 'date' => now()->toDateString(), 'expected_date' => now()->addDays($leadDays)->toDateString(),
                'description' => 'Awarded from RFQ '.$rfq->rfq_no, 'status' => 'submitted', 'created_by' => auth()->id(),
            ]);
            foreach ($rfq->lines as $line) \App\Models\PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->requested_qty, 'unit_price' => $quotes[$line->id]->unit_price]);
            $rfq->update(['status' => 'closed']);
            app(AuditService::class)->record('purchase_rfq.awarded', $rfq, ['status' => 'submitted'], ['status' => 'closed', 'purchase_order_id' => $order->id, 'supplier_id' => $response->supplier_id]);
        });
        return redirect()->route('procurement.orders')->with(['message' => 'Selected quotation awarded and purchase order created.', 'alert-type' => 'success']);
    }

    public function quote(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate([
            'rfq_supplier_id' => ['required', 'exists:purchase_rfq_suppliers,id'],
            'line_id' => ['required', 'array', 'min:1'], 'line_id.*' => ['required', 'exists:purchase_rfq_lines,id'],
            'unit_price' => ['required', 'array'], 'unit_price.*' => ['required', 'numeric', 'min:0'],
            'lead_days' => ['nullable', 'array'], 'lead_days.*' => ['nullable', 'integer', 'min:0'],
            'valid_until' => ['nullable', 'date'], 'supplier_reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $rfq = PurchaseRfq::where('company_id', $companyId)->findOrFail($id);
        if ($rfq->status !== 'submitted') return back()->with(['message' => 'Only submitted RFQs can receive quotations.', 'alert-type' => 'error']);
        $rfqSupplier = PurchaseRfqSupplier::where('purchase_rfq_id', $rfq->id)->findOrFail($data['rfq_supplier_id']);
        DB::transaction(function () use ($data, $rfq, $rfqSupplier): void {
            foreach ($data['line_id'] as $index => $lineId) {
                $line = PurchaseRfqLine::where('purchase_rfq_id', $rfq->id)->findOrFail($lineId);
                PurchaseSupplierQuotation::updateOrCreate(
                    ['purchase_rfq_supplier_id' => $rfqSupplier->id, 'purchase_rfq_line_id' => $line->id],
                    ['unit_price' => $data['unit_price'][$index], 'lead_days' => $data['lead_days'][$index] ?? null, 'valid_until' => $data['valid_until'] ?? null, 'supplier_reference' => $data['supplier_reference'] ?? null, 'notes' => $data['notes'] ?? null]
                );
            }
            $rfqSupplier->update(['status' => 'quoted', 'quoted_at' => now()]);
            app(AuditService::class)->record('purchase_rfq.quoted', $rfq, null, ['supplier_id' => $rfqSupplier->supplier_id]);
        });
        return back()->with(['message' => 'Supplier quotation recorded.', 'alert-type' => 'success']);
    }

    public function close(int $id)
    {
        $rfq = PurchaseRfq::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if ($rfq->status !== 'submitted') return back()->with(['message' => 'Only submitted RFQs can be closed.', 'alert-type' => 'error']);
        $rfq->update(['status' => 'closed']);
        app(AuditService::class)->record('purchase_rfq.closed', $rfq, ['status' => 'submitted'], ['status' => 'closed']);
        return back()->with(['message' => 'RFQ closed.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $rfq = PurchaseRfq::where('company_id', auth()->user()?->company_id)->findOrFail($id);
            if ($rfq->status !== 'submitted') throw new \RuntimeException('Only submitted RFQs can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($rfq);
            $before = $rfq->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $rfq->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('purchase_rfq.rejected', $rfq, $before, $rfq->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'RFQ rejected.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }
}
