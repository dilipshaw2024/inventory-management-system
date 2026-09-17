<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationLine;
use App\Services\ApprovalGuard;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesQuotationController extends Controller
{
    private function companyExists(string $table) { return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id')); }

    public function index()
    {
        $quotations = SalesQuotation::with(['customer', 'lines.product'])->latest()->paginate(30);
        return view('backend.invoice.quotations', compact('quotations'));
    }

    public function create()
    {
        $customers = Customer::where('status', 1)->orderBy('name')->get();
        $products = Product::where('status', 1)->orderBy('name')->get();
        return view('backend.invoice.quotation_add', compact('customers', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'quote_no' => ['nullable', 'string', 'max:100', 'unique:sales_quotations,quote_no'],
            'customer_id' => ['required', $this->companyExists('customers')],
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', $this->companyExists('products')],
            'quantity' => ['required', 'array', 'min:1'],
            'quantity.*' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'array', 'min:1'],
            'unit_price.*' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['required', 'array', 'min:1'],
            'discount_amount.*' => ['required', 'numeric', 'min:0'],
        ]);
        $quotation = SalesQuotation::create([
            'company_id' => auth()->user()?->company_id,
            'quote_no' => $data['quote_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('sales_quotation', 'QT-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
            'customer_id' => $data['customer_id'], 'quote_date' => $data['quote_date'], 'valid_until' => $data['valid_until'] ?? null,
            'description' => $data['description'] ?? null, 'created_by' => auth()->id(), 'status' => 'submitted',
        ]);
        foreach ($data['product_id'] as $index => $productId) {
            SalesQuotationLine::create(['sales_quotation_id' => $quotation->id, 'product_id' => $productId, 'quantity' => $data['quantity'][$index], 'unit_price' => $data['unit_price'][$index], 'discount_amount' => $data['discount_amount'][$index]]);
        }
        app(AuditService::class)->record('sales_quotation.created', $quotation, null, $quotation->toArray());
        return redirect()->route('sales.quotations.index')->with(['message' => 'Sales quotation submitted.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(SalesQuotation::class, $id);
        $quotation = SalesQuotation::with('lines')->findOrFail($id);
        if ($quotation->status !== 'submitted') return back()->with(['message' => 'Only submitted quotations can be approved.', 'alert-type' => 'error']);
        if ($quotation->valid_until && $quotation->valid_until->isPast()) return back()->with(['message' => 'This quotation has expired.', 'alert-type' => 'error']);
        try { app(ApprovalGuard::class)->assertDifferent($quotation); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        try { app(\App\Services\SalesDiscountPolicyService::class)->assertQuotationCanApprove($quotation); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $quotation->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        app(AuditService::class)->record('sales_quotation.approved', $quotation, ['status' => 'submitted'], ['status' => 'approved']);
        return back()->with(['message' => 'Sales quotation approved.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $quotation = SalesQuotation::findOrFail($id);
            if ($quotation->status !== 'submitted') throw new \RuntimeException('Only submitted quotations can be rejected.');
            app(ApprovalGuard::class)->assertDifferent($quotation);
            $before = $quotation->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $quotation->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('sales_quotation.rejected', $quotation, $before, $quotation->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'Sales quotation rejected.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function convert(int $id)
    {
        DB::transaction(function () use ($id): void {
            $quotation = SalesQuotation::with('lines')->lockForUpdate()->findOrFail($id);
            if ($quotation->status !== 'approved') throw new \RuntimeException('Only approved quotations can be converted.');
            if ($quotation->valid_until && $quotation->valid_until->isPast()) throw new \RuntimeException('This quotation has expired.');
            $order = SalesOrder::create([
                'company_id' => $quotation->company_id ?: auth()->user()?->company_id,
                'order_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_order', 'SO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                'customer_id' => $quotation->customer_id, 'date' => now()->toDateString(), 'requested_date' => $quotation->valid_until,
                'description' => 'Converted from quotation '.$quotation->quote_no, 'status' => 'submitted', 'created_by' => auth()->id(),
            ]);
            foreach ($quotation->lines as $line) SalesOrderLine::create(['sales_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount]);
            $quotation->update(['status' => 'converted']);
            app(AuditService::class)->record('sales_quotation.converted', $quotation, ['status' => 'approved'], ['status' => 'converted', 'sales_order_id' => $order->id]);
        });
        return redirect()->route('fulfillment.orders')->with(['message' => 'Quotation converted into a sales order.', 'alert-type' => 'success']);
    }
}
