<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierPayablesService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\CarbonImmutable;

class SupplierPaymentController extends Controller
{
    public function index()
    {
        $payments = SupplierPayment::with(['supplier', 'purchaseInvoice', 'allocations.invoice'])->latest()->paginate(30);
        $invoices = PurchaseInvoice::where('status', 'approved')->with(['supplier', 'lines'])->latest()->get();
        $outstanding = $invoices->map(function ($invoice) {
            $paid = (float) SupplierPayment::where('purchase_invoice_id', $invoice->id)->where('status', 'approved')->sum('amount');
            $allocated = (float) SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->sum('amount');
            $invoice->outstanding_amount = max(0, (float) $invoice->total_amount - $paid - $allocated);
            return $invoice;
        })->filter(fn ($invoice): bool => $invoice->outstanding_amount > 0.000001)->values();
        $suppliers = Supplier::orderBy('name')->get();
        return view('backend.purchase.payments', compact('payments', 'outstanding', 'invoices', 'suppliers'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['payment_no' => ['nullable', 'string', 'max:100', Rule::unique('supplier_payments', 'payment_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'supplier_id' => ['required', 'integer', $owned('suppliers')], 'purchase_invoice_id' => ['nullable', 'integer', $owned('purchase_invoices')], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'method' => ['required', 'in:cash,bank,card,transfer,other'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']]);
        $invoice = !empty($data['purchase_invoice_id']) ? PurchaseInvoice::findOrFail($data['purchase_invoice_id']) : null;
        if ($invoice && (int) $invoice->supplier_id !== (int) $data['supplier_id']) return back()->withErrors(['supplier_id' => 'Supplier does not match the selected invoice.'])->withInput();
        $currency = strtoupper($data['currency_code'] ?? ($invoice?->currency_code ?: (auth()->user()?->company?->base_currency ?? 'USD')));
        try {
            $rate = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : app(\App\Services\CurrencyConversionService::class)->rate($currency, auth()->user()?->company?->base_currency ?? 'USD', $data['payment_date']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['currency_code' => $exception->getMessage()])->withInput();
        }
        $payment = SupplierPayment::create($data + ['currency_code' => $currency, 'exchange_rate' => $rate, 'base_amount' => (float) $data['amount'] * $rate, 'payment_no' => $data['payment_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('supplier_payment', 'PAY-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'supplier_id' => $data['supplier_id'], 'status' => 'pending', 'created_by' => auth()->id()]);
        app(AuditService::class)->record('supplier_payment.created', $payment, null, $payment->toArray());
        return back()->with(['message' => 'Supplier payment submitted for approval.', 'alert-type' => 'success']);
    }

    public function allocate(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['payment_id' => ['required', 'integer', $owned('supplier_payments')], 'purchase_invoice_id' => ['required', 'integer', $owned('purchase_invoices')], 'amount' => ['required', 'numeric', 'gt:0'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0']]);
        try {
            $allocation = app(SupplierPaymentService::class)->allocate(SupplierPayment::findOrFail($data['payment_id']), (int) $data['purchase_invoice_id'], (float) $data['amount'], null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['allocation' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('supplier_payment.allocated', $allocation, null, $allocation->toArray());
        return back()->with(['message' => 'Supplier payment allocated.', 'alert-type' => 'success']);
    }

    public function voidAllocation(Request $request, int $id)
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);
        try { $allocation = app(SupplierPaymentService::class)->voidAllocation($id, $data['void_reason']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        app(AuditService::class)->record('supplier_payment_allocation.voided', $allocation, null, ['void_reason' => $data['void_reason']]);
        return back()->with(['message' => 'Supplier payment allocation voided.', 'alert-type' => 'success']);
    }

    public function aging(Request $request)
    {
        $asOf = $this->asOf($request);
        return view('backend.purchase.supplier_aging', ['rows' => $this->agingRows($asOf), 'asOf' => $asOf]);
    }

    public function agingExport(Request $request): StreamedResponse
    {
        $asOf = $this->asOf($request);
        $rows = $this->agingRows($asOf);
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Invoice', 'Supplier', 'Due date', 'Days overdue', 'Bucket', 'Collection status', 'Outstanding']);
            foreach ($rows as $row) fputcsv($output, [$row['invoice']->invoice_no ?: $row['invoice']->id, $row['invoice']->supplier->name, $row['due_date'], $row['days_overdue'], $row['bucket'], $row['collection_status'], number_format((float) $row['outstanding'], 2, '.', '')]);
            fclose($output);
        }, 'supplier-aging-'.$asOf.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function agingRows(string $asOf)
    {
        $invoices = PurchaseInvoice::with('supplier')->where('status', 'approved')->where(function ($query) use ($asOf): void { $query->whereDate('invoice_date', '<=', $asOf)->orWhere(function ($fallback) use ($asOf): void { $fallback->whereNull('invoice_date')->whereDate('created_at', '<=', $asOf); }); })->orderBy('due_date')->get();
        $invoiceIds = $invoices->pluck('id');
        $paid = $invoiceIds->isEmpty() ? collect() : SupplierPayment::whereIn('purchase_invoice_id', $invoiceIds)->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $asOf)->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $allocated = $invoiceIds->isEmpty() ? collect() : SupplierPaymentAllocation::whereIn('purchase_invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOf)->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $rows = $invoices->map(function (PurchaseInvoice $invoice) use ($paid, $allocated, $asOf): array {
            $paidAmount = (float) ($paid[$invoice->id] ?? 0);
            $allocatedAmount = (float) ($allocated[$invoice->id] ?? 0);
            $outstanding = max(0, (float) $invoice->total_amount - $paidAmount - $allocatedAmount);
            $dueDate = app(SupplierPayablesService::class)->dueDate($invoice);
            $days = CarbonImmutable::parse($asOf)->startOfDay()->diffInDays($dueDate, false) * -1;
            $bucket = $outstanding <= 0.000001 ? 'paid' : ($days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'))));
            $invoice->setAttribute('due_date', $dueDate);
            return ['invoice' => $invoice, 'due_date' => $dueDate->toDateString(), 'outstanding' => $outstanding, 'days_overdue' => max(0, $days), 'bucket' => $bucket, 'collection_status' => app(SupplierPayablesService::class)->collectionStatus($outstanding, max(0, $days))];
        })->filter(fn (array $row): bool => $row['outstanding'] > 0.000001)->values();
        return $rows;
    }

    private function asOf(Request $request): string
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);
        return (string) ($validated['as_of'] ?? now()->toDateString());
    }

    public function approve(int $id)
    {
        try { app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(SupplierPayment::class, $id); $payment = SupplierPayment::findOrFail($id); if ($payment->status !== 'pending') throw new \RuntimeException('This payment has already been processed.'); app(\App\Services\ApprovalGuard::class)->assertDifferent($payment); app(SupplierPaymentService::class)->approve($payment); app(AuditService::class)->record('supplier_payment.approved', $payment, ['status' => 'pending'], ['status' => 'approved']); return back()->with(['message' => 'Supplier payment approved and journalized.', 'alert-type' => 'success']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $payment = SupplierPayment::findOrFail($id);
        if ($payment->status !== 'pending') return back()->with(['message' => 'Only pending supplier payments can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($payment); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $payment->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $payment->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('supplier_payment.rejected', $payment, $before, $payment->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Supplier payment rejected.', 'alert-type' => 'success']);
    }
}
