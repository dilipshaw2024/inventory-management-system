<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\PurchaseInvoiceService;
use App\Services\TaxRateResolver;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseInvoiceController extends Controller
{
    public function index()
    {
        $invoices = PurchaseInvoice::with('supplier')->latest()->paginate(30);
        return view('backend.purchase.invoice_all', compact('invoices'));
    }

    public function create()
    {
        $orders = PurchaseOrder::whereIn('status', ['approved', 'partially_received', 'received'])->with(['supplier', 'lines.product'])->latest()->get();
        $defaultTaxMode = app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
        return view('backend.purchase.invoice_add', compact('orders', 'defaultTaxMode'));
    }

    public function priceVariance(Request $request)
    {
        $data = $request->validate(['supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'], 'minimum_percent' => ['nullable', 'numeric', 'min:0', 'max:1000']]);
        $invoices = PurchaseInvoice::with(['supplier', 'lines.product', 'lines.purchaseOrderLine'])
            ->when($data['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->latest('invoice_date')->get();
        $minimum = (float) ($data['minimum_percent'] ?? 0);
        $rows = collect();
        foreach ($invoices as $invoice) foreach ($invoice->lines as $line) {
            $orderedPrice = (float) ($line->purchaseOrderLine?->unit_price ?? 0);
            if ($orderedPrice <= 0) continue;
            $variance = ((float) $line->unit_price - $orderedPrice) / $orderedPrice * 100;
            if (abs($variance) < $minimum) continue;
            $rows->push(['invoice' => $invoice, 'line' => $line, 'ordered_price' => $orderedPrice, 'variance_percent' => $variance, 'variance_amount' => ((float) $line->unit_price - $orderedPrice) * (float) $line->quantity]);
        }
        $tolerance = (float) app(\App\Services\ErpSettingService::class)->get('purchase_price_variance_percent', 0);
        return view('backend.purchase.price_variance', compact('rows', 'tolerance', 'minimum'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $orderScope = Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $lineScope = Rule::exists('purchase_order_lines', 'id')->whereIn('purchase_order_id', PurchaseOrder::withoutGlobalScopes()->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->select('id'));
        $data = $request->validate(['invoice_no' => ['nullable', 'string', 'max:100', Rule::unique('purchase_invoices', 'invoice_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'purchase_order_id' => ['required', 'integer', $orderScope], 'invoice_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'tax_mode' => ['nullable', 'in:exclusive,inclusive'], 'description' => ['nullable', 'string', 'max:2000'], 'line_id' => ['required', 'array', 'min:1'], 'line_id.*' => ['required', 'integer', $lineScope], 'quantity' => ['required', 'array'], 'quantity.*' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'array'], 'unit_price.*' => ['required', 'numeric', 'min:0'], 'tax_rate' => ['nullable', 'array'], 'tax_rate.*' => ['nullable', 'numeric', 'min:0', 'max:100']]);
            $order = PurchaseOrder::findOrFail($data['purchase_order_id']);
            $currency = strtoupper($data['currency_code'] ?? ($order->currency_code ?: (auth()->user()?->company?->base_currency ?? 'USD')));
        $baseCurrency = strtoupper(auth()->user()?->company?->base_currency ?? 'USD');
        try {
            $exchangeRate = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : app(\App\Services\CurrencyConversionService::class)->rate($currency, $baseCurrency, $data['invoice_date']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['currency_code' => $exception->getMessage()])->withInput();
        }
        $supplier = Supplier::findOrFail($order->supplier_id);
        $taxExempt = (bool) $supplier->tax_exempt;
        $taxExemptionNumber = Supplier::whereKey($order->supplier_id)->value('tax_exemption_number');
        $invoice = DB::transaction(function () use ($data, $currency, $exchangeRate, $taxExempt, $taxExemptionNumber, $supplier): PurchaseInvoice {
            $order = PurchaseOrder::findOrFail($data['purchase_order_id']);
            $subtotal = 0; $tax = 0; $taxMode = $data['tax_mode'] ?? app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
            $dueDate = $data['due_date'] ?? \Carbon\CarbonImmutable::parse($data['invoice_date'])->addDays((int) $supplier->payment_terms_days)->toDateString();
            $invoice = PurchaseInvoice::create(['invoice_no' => $data['invoice_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('purchase_invoice', 'PINV-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'invoice_date' => $data['invoice_date'], 'due_date' => $dueDate, 'currency_code' => $currency, 'exchange_rate' => $exchangeRate, 'tax_mode' => $taxMode, 'tax_exempt' => $taxExempt, 'tax_exemption_number' => $taxExempt ? $taxExemptionNumber : null, 'tax_jurisdiction' => $supplier->tax_jurisdiction, 'description' => $data['description'] ?? null, 'created_by' => auth()->id()]);
            foreach ($data['line_id'] as $index => $lineId) {
                $poLine = $order->lines()->whereKey($lineId)->firstOrFail(); $qty = (float) $data['quantity'][$index]; $price = (float) $data['unit_price'][$index]; $lineSubtotal = $qty * $price; $rate = $taxExempt ? 0 : (isset($data['tax_rate'][$index]) ? (float) $data['tax_rate'][$index] : app(TaxRateResolver::class)->rateFor($poLine->product, $data['invoice_date'], $supplier->tax_jurisdiction)); $taxResult = $taxMode === 'inclusive' ? app(\App\Services\TaxCalculationService::class)->inclusive($lineSubtotal, $rate) : ['net' => $lineSubtotal, 'tax' => app(\App\Services\TaxCalculationService::class)->exclusive($lineSubtotal, $rate)]; $lineTax = $taxResult['tax'];
                $subtotal += $taxResult['net']; $tax += $lineTax;
                PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'purchase_order_line_id' => $poLine->id, 'product_id' => $poLine->product_id, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $rate, 'tax_amount' => $lineTax, 'line_total' => $lineSubtotal + ($taxMode === 'inclusive' ? 0 : $lineTax)]);
            }
            $invoice->update(['subtotal_amount' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $subtotal + $tax]);
            app(AuditService::class)->record('purchase_invoice.created', $invoice, null, $invoice->toArray());
            return $invoice;
        });
        return redirect()->route('procurement.invoices.index')->with(['message' => 'Purchase invoice submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        try { app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(PurchaseInvoice::class, $id); $invoice = PurchaseInvoice::with(['lines.product'])->findOrFail($id); if ($invoice->status !== 'pending') throw new \RuntimeException('This invoice has already been processed.'); app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice); app(PurchaseInvoiceService::class)->approve($invoice); app(AuditService::class)->record('purchase_invoice.approved', $invoice, ['status' => 'pending'], ['status' => 'approved']); return back()->with(['message' => 'Purchase invoice approved and posted to accounts payable.', 'alert-type' => 'success']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        try {
            $invoice = PurchaseInvoice::findOrFail($id);
            if ($invoice->status !== 'pending') throw new \RuntimeException('Only pending purchase invoices can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
            $before = $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $invoice->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('purchase_invoice.rejected', $invoice, $before, $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'Purchase invoice rejected without posting an AP journal.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
    }
}
