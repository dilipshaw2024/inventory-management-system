<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\CostCenterBudget;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\CustomerRefund;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerCreditNote;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPaymentAllocation;
use App\Models\EInvoiceSubmission;
use App\Models\Company;
use App\Services\AccountingService;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\CustomerCreditService;
use App\Services\SupplierPayablesService;
use App\Services\AccountingReconciliationService;
use App\Services\EInvoiceService;
use App\Services\SupplierPaymentProposalService;
use App\Services\CustomerReceiptProposalService;
use App\Services\CurrencyConversionService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingIntegrationController extends Controller
{
    public function eInvoices(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for e-invoice submissions.');
        $submissions = EInvoiceSubmission::with('invoice')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')->paginate(min(100, max(1, (int) $request->input('per_page', 50))));
        return response()->json($submissions);
    }

    public function prepareEInvoice(Request $request, int $id, EInvoiceService $service): JsonResponse
    {
        $data = $request->validate(['provider' => ['nullable', 'string', 'max:80']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for e-invoice submissions.');
        $invoice = Invoice::with(['customer', 'invoice_details.product'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($id);
        try {
            $submission = $service->prepare($invoice, $data['provider'] ?? 'generic', $request->user()?->id);
        } catch (\RuntimeException | \InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($submission->wasRecentlyCreated) app(AuditService::class)->record('e_invoice.prepared', $submission, null, $submission->toArray());
        return response()->json(['data' => $submission, 'idempotent' => $submission->wasRecentlyCreated === false], $submission->wasRecentlyCreated ? 201 : 200);
    }

    public function submitEInvoice(Request $request, int $id, EInvoiceService $service): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for e-invoice submissions.');
        $submission = EInvoiceSubmission::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($id);
        try {
            $submission = $service->submit($submission);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $submission->fresh()], 422);
        }
        app(AuditService::class)->record('e_invoice.submitted', $submission, null, $submission->toArray());
        return response()->json(['data' => $submission]);
    }

    public function eInvoiceCallback(Request $request, string $provider, EInvoiceService $service): JsonResponse
    {
        $secret = (string) config('integrations.e_invoice_callback_secret');
        $signature = (string) $request->header('X-ERP-EINVOICE-SIGNATURE');
        $body = $request->getContent();
        if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $signature)) return response()->json(['message' => 'Invalid e-invoice callback signature.'], 401);
        $data = $request->validate(['external_reference' => ['required', 'string', 'max:190'], 'status' => ['required', 'in:submitted,accepted,rejected,pending'], 'response' => ['nullable', 'array']]);
        try {
            $submission = $service->acknowledge($provider, $data['external_reference'], $data['status'], $data['response'] ?? null);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        app(AuditService::class)->record('e_invoice.callback', $submission, null, $submission->toArray());
        return response()->json(['data' => $submission]);
    }

    public function supplierAging(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request); abort_unless($companyId, 403, 'A company is required for payables aging.');
        $companyScope = fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
        $asOf = $data['as_of'] ?? now()->toDateString();
        $invoices = PurchaseInvoice::with('supplier')->where($companyScope)->where('status', 'approved')->where(function ($query) use ($asOf): void {
            $query->whereDate('invoice_date', '<=', $asOf)
                ->orWhere(function ($fallback) use ($asOf): void {
                    $fallback->whereNull('invoice_date')->whereDate('created_at', '<=', $asOf);
                });
        })->when($data['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))->get();
        $invoiceIds = $invoices->pluck('id');
        $paid = SupplierPayment::where($companyScope)->whereIn('purchase_invoice_id', $invoiceIds)->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $asOf)->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $allocated = SupplierPaymentAllocation::where($companyScope)->whereIn('purchase_invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOf)->whereHas('payment', fn ($q) => $q->where('status', 'approved')->where('is_reversed', false))->selectRaw('purchase_invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('purchase_invoice_id')->pluck('amount', 'purchase_invoice_id');
        $rows = $invoices->map(function (PurchaseInvoice $invoice) use ($paid, $allocated, $asOf): ?array {
            $outstanding = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0)); if ($outstanding <= 0.000001) return null;
            $dueDate = app(SupplierPayablesService::class)->dueDate($invoice); $days = max(0, $dueDate->diffInDays(\Carbon\Carbon::parse($asOf), false));
            return ['supplier' => $invoice->supplier, 'invoice' => $invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $this->agingBucket($days), 'outstanding' => $outstanding, 'collection_status' => app(SupplierPayablesService::class)->collectionStatus($outstanding, $days)];
        })->filter()->values();
        $page = max(1, (int) $request->input('page', 1)); $perPage = (int) ($data['per_page'] ?? 50); $bucketSummary = $rows->groupBy('bucket')->map(fn ($bucketRows) => (float) $bucketRows->sum('outstanding'));
        return response()->json(['data' => $rows->sortByDesc('days_overdue')->forPage($page, $perPage)->values(), 'summary' => ['total' => (float) $rows->sum('outstanding'), 'current' => (float) ($bucketSummary['current'] ?? 0), '1-30' => (float) ($bucketSummary['1-30'] ?? 0), '31-60' => (float) ($bucketSummary['31-60'] ?? 0), '61-90' => (float) ($bucketSummary['61-90'] ?? 0), '90+' => (float) ($bucketSummary['90+'] ?? 0)], 'meta' => ['as_of' => $asOf, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function supplierPaymentProposals(Request $request, SupplierPaymentProposalService $service): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'due_by' => ['nullable', 'date', 'after_or_equal:as_of'], 'supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for supplier payment proposals.');
        $result = $service->propose($companyId, $data['as_of'] ?? null, $data['due_by'] ?? null, $data['supplier_id'] ?? null);
        $rows = $result['rows'];
        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) ($data['per_page'] ?? 50);
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'batches' => $result['batches'], 'summary' => ['invoice_count' => $rows->count(), 'proposed_amount' => round((float) $rows->sum('proposed_amount'), 6), 'unallocated_credits' => $result['unallocated_credits']], 'meta' => ['as_of' => $data['as_of'] ?? now()->toDateString(), 'due_by' => $data['due_by'] ?? ($data['as_of'] ?? now()->toDateString()), 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function createSupplierPaymentRun(Request $request, SupplierPaymentProposalService $service): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for supplier payment runs.');
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:150'], 'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'], 'invoice_ids.*' => ['required', 'integer', Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'payment_date' => ['required', 'date'], 'method' => ['required', 'in:cash,bank,card,transfer,other'], 'currency_code' => ['nullable', 'string', 'size:3'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'as_of' => ['nullable', 'date'], 'due_by' => ['nullable', 'date', 'after_or_equal:as_of'],
        ]);
        $result = $service->propose($companyId, $data['as_of'] ?? null, $data['due_by'] ?? null, (int) $data['supplier_id']);
        $selectedIds = collect($data['invoice_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $selected = $result['rows']->whereIn('purchase_invoice_id', $selectedIds)->values();
        if ($selected->count() !== $selectedIds->count()) abort(422, 'One or more selected invoices are not currently eligible for this payment run.');
        $currencies = $selected->pluck('currency_code')->unique()->values();
        if ($currencies->count() !== 1 || (isset($data['currency_code']) && strtoupper($data['currency_code']) !== $currencies->first())) abort(422, 'A payment run must contain invoices in one currency.');
        $currency = $currencies->first();
        $externalReferences = $selected->mapWithKeys(fn (array $row): array => [$row['purchase_invoice_id'] => $data['external_reference'].':'.$row['purchase_invoice_id']]);
        $existing = SupplierPayment::where('company_id', $companyId)->whereIn('external_reference', $externalReferences->values())->get()->keyBy('external_reference');
        if ($existing->count() === $selected->count()) return response()->json(['data' => $existing->values()->each(fn ($payment) => $payment->load('supplier', 'purchaseInvoice')), 'status' => 'duplicate_ignored', 'payment_run_reference' => $data['external_reference']]);
        if ($existing->isNotEmpty()) abort(409, 'The payment run was partially created; use its external reference to replay it safely.');
        $baseCurrency = strtoupper((string) ($request->user()?->company?->base_currency ?: 'USD'));
        try { $rate = app(\App\Services\CurrencyConversionService::class)->rate($currency, $baseCurrency, $data['payment_date']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $payments = DB::transaction(function () use ($selected, $data, $companyId, $externalReferences, $currency, $rate): array {
            return $selected->map(function (array $row) use ($data, $companyId, $externalReferences, $currency, $rate): SupplierPayment {
                $payment = SupplierPayment::create(['company_id' => $companyId, 'external_reference' => $externalReferences[$row['purchase_invoice_id']], 'payment_no' => app(\App\Services\NumberingSequenceService::class)->nextOrFallback('supplier_payment', 'PAY-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $data['branch_id'] ?? null), 'supplier_id' => $row['supplier_id'], 'purchase_invoice_id' => $row['purchase_invoice_id'], 'payment_date' => $data['payment_date'], 'amount' => $row['proposed_amount'], 'method' => $data['method'], 'currency_code' => $currency, 'exchange_rate' => $rate, 'base_amount' => (float) $row['proposed_amount'] * $rate, 'reference' => $data['reference'] ?? null, 'description' => ($data['description'] ?? 'Payment run').' ['.$data['external_reference'].']', 'status' => 'pending', 'created_by' => request()->user()?->id]);
                app(AuditService::class)->record('supplier_payment.run_created', $payment, null, $payment->toArray() + ['payment_run_reference' => $data['external_reference']]);
                return $payment;
            })->all();
        });
        $paymentCollection = collect($payments)->each(fn ($payment) => $payment->load('supplier', 'purchaseInvoice'));
        return response()->json(['data' => $paymentCollection, 'status' => 'pending_approval', 'payment_run_reference' => $data['external_reference'], 'total_amount' => round((float) $selected->sum('proposed_amount'), 6)], 201);
    }

    public function supplierPaymentRemittance(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._-]+$/'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for supplier remittance exports.');

        $reference = $data['external_reference'];
        $payments = SupplierPayment::with(['supplier:id,name', 'purchaseInvoice:id,invoice_no,invoice_date,due_date,total_amount,currency_code'])
            ->where('company_id', $companyId)
            ->where('external_reference', 'like', $reference.':%')
            ->orderBy('id')
            ->get();
        abort_if($payments->isEmpty(), 404, 'No supplier payments were found for this payment run.');

        $rows = $payments->map(fn (SupplierPayment $payment): array => [
            'payment_no' => $payment->payment_no,
            'external_reference' => $payment->external_reference,
            'supplier_id' => $payment->supplier_id,
            'supplier_name' => $payment->supplier?->name,
            'purchase_invoice_id' => $payment->purchase_invoice_id,
            'invoice_no' => $payment->purchaseInvoice?->invoice_no,
            'invoice_date' => $payment->purchaseInvoice?->invoice_date?->toDateString(),
            'due_date' => $payment->purchaseInvoice?->due_date?->toDateString(),
            'payment_date' => $payment->payment_date?->toDateString(),
            'method' => $payment->method,
            'currency_code' => $payment->currency_code,
            'amount' => round((float) $payment->amount, 6),
            'base_amount' => round((float) ($payment->base_amount ?? $payment->amount), 6),
            'status' => $payment->status,
            'is_reversed' => (bool) $payment->is_reversed,
            'reference' => $payment->reference,
        ])->values();
        $summary = [
            'payment_count' => $rows->count(),
            'total_amount' => round((float) $rows->sum('amount'), 6),
            'approved_amount' => round((float) $rows->where('status', 'approved')->sum('amount'), 6),
            'pending_amount' => round((float) $rows->where('status', 'pending')->sum('amount'), 6),
            'rejected_amount' => round((float) $rows->where('status', 'rejected')->sum('amount'), 6),
            'reversed_count' => $rows->where('is_reversed', true)->count(),
            'currencies' => $rows->pluck('currency_code')->filter()->unique()->values()->all(),
        ];
        if (($data['format'] ?? 'json') !== 'csv') {
            return response()->json(['payment_run_reference' => $reference, 'generated_at' => now()->toIso8601String(), 'summary' => $summary, 'data' => $rows]);
        }

        return response()->streamDownload(function () use ($reference, $summary, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['payment_run_reference', $reference]);
            fputcsv($handle, ['payment_count', $summary['payment_count'], 'total_amount', $summary['total_amount']]);
            fputcsv($handle, array_keys($rows->first()));
            foreach ($rows as $row) fputcsv($handle, array_values($row));
            fclose($handle);
        }, 'supplier-remittance-'.$reference.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function customerAging(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'customer_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request); abort_unless($companyId, 403, 'A company is required for receivables aging.');
        $companyScope = fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
        $asOf = $data['as_of'] ?? now()->toDateString();
        $invoices = Invoice::with('customer')->where($companyScope)->where('status', 1)->whereNotNull('customer_id')->whereDate('date', '<=', $asOf)->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))->get();
        $invoiceIds = $invoices->pluck('id');
        $paid = Payment::where($companyScope)->whereIn('invoice_id', $invoiceIds)->where('approval_status', 'approved')->where('is_reversed', false)->where(fn ($query) => $query->whereDate('payment_date', '<=', $asOf)->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $asOf)))->selectRaw('invoice_id, COALESCE(SUM(paid_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $allocated = CustomerPaymentAllocation::where($companyScope)->whereIn('invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOf)->whereHas('payment', fn ($q) => $q->where('approval_status', 'approved')->where('is_reversed', false))->selectRaw('invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $credits = CustomerCreditNote::where($companyScope)->whereIn('invoice_id', $invoiceIds)->where('status', 'approved')->whereDate('credit_date', '<=', $asOf)->selectRaw('invoice_id, COALESCE(SUM(total_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $rows = $invoices->map(function (Invoice $invoice) use ($paid, $allocated, $credits, $asOf): ?array {
            $outstanding = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0) - (float) ($credits[$invoice->id] ?? 0)); if ($outstanding <= 0.000001) return null;
            $dueDate = \Carbon\Carbon::parse($invoice->date ?? $invoice->created_at)->addDays((int) ($invoice->customer?->credit_days ?? 0));
            $days = max(0, $dueDate->diffInDays(\Carbon\Carbon::parse($asOf), false));
            return ['customer' => $invoice->customer, 'invoice' => $invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $this->agingBucket($days), 'outstanding' => $outstanding, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($invoice->customer ?: new \App\Models\Customer(), $outstanding, $days)];
        })->filter()->values();
        $legacyIds = $invoiceIds->all() ?: [-1];
        $legacy = Payment::with(['customer', 'invoice'])->where($companyScope)->where('approval_status', 'approved')->where('due_amount', '>', 0)->whereNotIn('invoice_id', $legacyIds)->where(fn ($query) => $query->whereDate('payment_date', '<=', $asOf)->orWhere(fn ($fallback) => $fallback->whereNull('payment_date')->whereDate('created_at', '<=', $asOf)))->get()->map(function (Payment $payment) use ($asOf): array {
            $date = $payment->invoice?->date ?? $payment->payment_date ?? $payment->created_at; $dueDate = \Carbon\Carbon::parse($date)->addDays((int) ($payment->customer?->credit_days ?? 0)); $days = max(0, $dueDate->diffInDays(\Carbon\Carbon::parse($asOf), false));
            return ['customer' => $payment->customer, 'invoice' => $payment->invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $this->agingBucket($days), 'outstanding' => (float) $payment->due_amount, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($payment->customer ?: new \App\Models\Customer(), (float) $payment->due_amount, $days)];
        });
        $rows = $rows->concat($legacy)->filter(fn (array $row) => !($data['customer_id'] ?? null) || (int) ($row['customer']->id ?? 0) === (int) $data['customer_id'])->sortByDesc('days_overdue')->values();
        $page = max(1, (int) $request->input('page', 1)); $perPage = (int) ($data['per_page'] ?? 50); $bucketSummary = $rows->groupBy('bucket')->map(fn ($bucketRows) => (float) $bucketRows->sum('outstanding'));
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['total' => (float) $rows->sum('outstanding'), 'current' => (float) ($bucketSummary['current'] ?? 0), '1-30' => (float) ($bucketSummary['1-30'] ?? 0), '31-60' => (float) ($bucketSummary['31-60'] ?? 0), '61-90' => (float) ($bucketSummary['61-90'] ?? 0), '90+' => (float) ($bucketSummary['90+'] ?? 0)], 'meta' => ['as_of' => $asOf, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function customerReceiptProposals(Request $request, CustomerReceiptProposalService $service): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'due_by' => ['nullable', 'date', 'after_or_equal:as_of'], 'customer_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for customer receipt proposals.');
        $result = $service->propose($companyId, $data['as_of'] ?? null, $data['due_by'] ?? null, $data['customer_id'] ?? null);
        $rows = $result['rows']; $page = max(1, (int) $request->input('page', 1)); $perPage = (int) ($data['per_page'] ?? 50);
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'batches' => $result['batches'], 'summary' => ['invoice_count' => $rows->count(), 'proposed_amount' => round((float) $rows->sum('proposed_amount'), 6)], 'meta' => ['as_of' => $data['as_of'] ?? now()->toDateString(), 'due_by' => $data['due_by'] ?? ($data['as_of'] ?? now()->toDateString()), 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function createCustomerReceiptRun(Request $request, CustomerReceiptProposalService $service): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:150'], 'customer_id' => ['nullable', 'integer'], 'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer'], 'payment_date' => ['required', 'date'], 'method' => ['required', 'in:cash,bank,card,transfer,other'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'as_of' => ['nullable', 'date'], 'due_by' => ['nullable', 'date', 'after_or_equal:as_of'],
        ]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for customer receipt runs.');
        $existing = Payment::where('company_id', $companyId)->where('external_reference', 'like', $data['external_reference'].':%')->get();
        if ($existing->isNotEmpty()) {
            if ($existing->count() !== count($data['invoice_ids'])) return response()->json(['message' => 'A partial receipt run already exists for this external reference.'], 409);
            return response()->json(['data' => $existing->load('customer', 'invoice'), 'status' => 'duplicate_ignored', 'receipt_run_reference' => $data['external_reference']]);
        }
        $result = $service->propose($companyId, $data['as_of'] ?? null, $data['due_by'] ?? null, $data['customer_id'] ?? null);
        $selected = $result['rows']->whereIn('invoice_id', $data['invoice_ids'])->values();
        if ($selected->count() !== count(array_unique($data['invoice_ids']))) return response()->json(['message' => 'One or more invoices are not currently eligible for this receipt run.'], 422);
        if ($selected->pluck('customer_id')->unique()->count() > 1) return response()->json(['message' => 'A receipt run must contain one customer.'], 422);
        $currencies = $selected->pluck('currency_code')->map(fn ($currency): string => strtoupper((string) $currency))->values()->unique();
        if ($currencies->count() !== 1 || (!empty($data['currency_code']) && strtoupper($data['currency_code']) !== $currencies->first())) return response()->json(['message' => 'All receipt invoices must use the same currency.'], 422);
        $currency = $currencies->first();
        $baseCurrency = strtoupper((string) ($request->user()?->company?->base_currency ?: 'USD'));
        try { $rate = app(CurrencyConversionService::class)->rate($currency, $baseCurrency, $data['payment_date']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $payments = DB::transaction(function () use ($selected, $data, $companyId, $currency, $rate, $request): array {
            return $selected->map(function (array $row) use ($data, $companyId, $currency, $rate, $request): Payment {
                $payment = Payment::create(['company_id' => $companyId, 'external_reference' => $data['external_reference'].':'.$row['invoice_id'], 'customer_id' => $row['customer_id'], 'invoice_id' => $row['invoice_id'], 'paid_status' => 'unallocated', 'payment_date' => $data['payment_date'], 'approval_status' => 'pending', 'paid_amount' => $row['proposed_amount'], 'due_amount' => 0, 'total_amount' => $row['proposed_amount'], 'currency_code' => $currency, 'exchange_rate' => $rate, 'base_amount' => (float) $row['proposed_amount'] * $rate, 'reference' => $data['reference'] ?? null, 'created_by' => $request->user()?->id]);
                app(AuditService::class)->record('customer_payment.run_created', $payment, null, $payment->toArray() + ['receipt_run_reference' => $data['external_reference']]);
                return $payment;
            })->all();
        });
        $collection = collect($payments)->each(fn ($payment) => $payment->load('customer', 'invoice'));
        return response()->json(['data' => $collection, 'status' => 'pending_approval', 'receipt_run_reference' => $data['external_reference'], 'total_amount' => round((float) $selected->sum('proposed_amount'), 6)], 201);
    }

    public function customerStatement(Request $request, int $customerId): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for customer statements.');
        $customer = Customer::whereKey($customerId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        $from = \Carbon\CarbonImmutable::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = \Carbon\CarbonImmutable::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $scope = fn ($query) => $query->where(fn ($company) => $company->where('company_id', $companyId)->orWhereNull('company_id'));
        $entries = collect();
        Invoice::where($scope)->where('customer_id', $customer->id)->where('status', 1)->where(function ($query) use ($to): void { $query->whereDate('date', '<=', $to->toDateString())->orWhere(function ($fallback) use ($to): void { $fallback->whereNull('date')->whereDate('created_at', '<=', $to->toDateString()); }); })->get()->each(function (Invoice $invoice) use ($entries): void {
            $entries->push(['occurred_at' => ($invoice->date ?: $invoice->created_at)->toDateString(), 'type' => 'invoice', 'reference' => $invoice->invoice_no ?: 'INV-'.$invoice->id, 'source_id' => $invoice->id, 'debit' => (float) $invoice->total_amount, 'credit' => 0.0, 'currency_code' => $invoice->currency_code]);
        });
        CustomerCreditNote::where($scope)->where('customer_id', $customer->id)->where('status', 'approved')->whereDate('credit_date', '<=', $to->toDateString())->get()->each(function (CustomerCreditNote $note) use ($entries): void {
            $entries->push(['occurred_at' => $note->credit_date->toDateString(), 'type' => 'customer_credit_note', 'reference' => $note->credit_no, 'source_id' => $note->id, 'invoice_id' => $note->invoice_id, 'debit' => 0.0, 'credit' => (float) $note->total_amount, 'currency_code' => $note->invoice?->currency_code]);
        });
        Payment::where($scope)->where('customer_id', $customer->id)->where('approval_status', 'approved')->where(function ($query): void { $query->where('is_reversed', false)->orWhereNull('is_reversed'); })->where(fn ($query) => $query->whereDate('payment_date', '<=', $to->toDateString())->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $to->toDateString())))->with(['allocations' => fn ($query) => $query->whereDate('allocated_at', '<=', $to->toDateString())])->get()->each(function (Payment $payment) use ($entries): void {
            $entries->push(['occurred_at' => ($payment->payment_date ?: $payment->created_at)->toDateString(), 'type' => 'payment', 'reference' => $payment->reference ?: 'PAY-'.$payment->id, 'source_id' => $payment->id, 'debit' => 0.0, 'credit' => (float) $payment->paid_amount, 'currency_code' => $payment->currency_code, 'allocated_amount' => (float) $payment->allocations->sum('amount'), 'allocated_invoice_amount' => (float) $payment->allocations->sum('amount'), 'allocated_payment_amount' => (float) $payment->allocations->sum(fn ($allocation): float => (float) ($allocation->payment_amount ?? $allocation->amount)), 'allocation_count' => $payment->allocations->count()]);
        });
        $entries = $entries->sortBy(fn (array $entry): string => $entry['occurred_at'].':'.str_pad((string) $entry['source_id'], 12, '0', STR_PAD_LEFT))->values();
        $opening = (float) $entries->filter(fn (array $entry): bool => $entry['occurred_at'] < $from->toDateString())->sum(fn (array $entry): float => $entry['debit'] - $entry['credit']);
        $running = $opening;
        $rows = $entries->filter(fn (array $entry): bool => $entry['occurred_at'] >= $from->toDateString() && $entry['occurred_at'] <= $to->toDateString())->map(function (array $entry) use (&$running): array {
            $running += $entry['debit'] - $entry['credit'];
            return $entry + ['balance' => round($running, 6)];
        })->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['opening_balance' => round($opening, 6), 'period_debits' => round((float) $rows->sum('debit'), 6), 'period_credits' => round((float) $rows->sum('credit'), 6), 'closing_balance' => round($running, 6)], 'meta' => ['customer' => $customer, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function supplierStatement(Request $request, int $supplierId): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for supplier statements.');
        $supplier = \App\Models\Supplier::whereKey($supplierId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        $from = \Carbon\CarbonImmutable::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = \Carbon\CarbonImmutable::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $scope = fn ($query) => $query->where(fn ($company) => $company->where('company_id', $companyId)->orWhereNull('company_id'));
        $entries = collect();
        PurchaseInvoice::where($scope)->where('supplier_id', $supplier->id)->where('status', 'approved')->whereDate('invoice_date', '<=', $to->toDateString())->get()->each(function (PurchaseInvoice $invoice) use ($entries): void {
            $entries->push(['occurred_at' => ($invoice->invoice_date ?: $invoice->created_at)->toDateString(), 'type' => 'purchase_invoice', 'reference' => $invoice->invoice_no ?: 'PINV-'.$invoice->id, 'source_id' => $invoice->id, 'debit' => 0.0, 'credit' => (float) $invoice->total_amount, 'currency_code' => $invoice->currency_code]);
        });
        SupplierPayment::where($scope)->where('supplier_id', $supplier->id)->where('status', 'approved')->where(function ($query): void { $query->where('is_reversed', false)->orWhereNull('is_reversed'); })->whereDate('payment_date', '<=', $to->toDateString())->with(['allocations' => fn ($query) => $query->whereDate('allocated_at', '<=', $to->toDateString())])->get()->each(function (SupplierPayment $payment) use ($entries): void {
            $entries->push(['occurred_at' => $payment->payment_date->toDateString(), 'type' => 'supplier_payment', 'reference' => $payment->payment_no ?: 'PAY-'.$payment->id, 'source_id' => $payment->id, 'debit' => (float) $payment->amount, 'credit' => 0.0, 'currency_code' => $payment->currency_code, 'allocated_amount' => (float) $payment->allocations->sum('amount'), 'allocated_invoice_amount' => (float) $payment->allocations->sum('amount'), 'allocated_payment_amount' => (float) $payment->allocations->sum(fn ($allocation): float => (float) ($allocation->payment_amount ?? $allocation->amount)), 'allocation_count' => $payment->allocations->count()]);
        });
        \App\Models\SupplierClaim::where($scope)->where('supplier_id', $supplier->id)->whereIn('status', ['partially_settled', 'settled'])->where('settled_amount', '>', 0)->whereDate('settled_at', '<=', $to->toDateString())->get()->each(function (\App\Models\SupplierClaim $claim) use ($entries): void {
            $entries->push(['occurred_at' => optional($claim->settled_at)->toDateString() ?: $claim->claim_date->toDateString(), 'type' => 'supplier_claim_settlement', 'reference' => $claim->settlement_reference ?: $claim->claim_no, 'source_id' => $claim->id, 'purchase_invoice_id' => $claim->purchase_invoice_id, 'debit' => (float) $claim->settled_amount, 'credit' => 0.0, 'currency_code' => null]);
        });
        \App\Models\SupplierCreditNote::where($scope)->where('supplier_id', $supplier->id)->whereNull('supplier_claim_id')->where('status', 'approved')->where('total_amount', '>', 0)->whereDate('approved_at', '<=', $to->toDateString())->get()->each(function (\App\Models\SupplierCreditNote $note) use ($entries): void {
            $entries->push(['occurred_at' => optional($note->approved_at)->toDateString() ?: $note->credit_date->toDateString(), 'type' => 'supplier_credit_note', 'reference' => $note->credit_no, 'source_id' => $note->id, 'purchase_invoice_id' => $note->purchase_invoice_id, 'debit' => (float) $note->total_amount, 'credit' => 0.0, 'currency_code' => null]);
        });
        $entries = $entries->sortBy(fn (array $entry): string => $entry['occurred_at'].':'.str_pad((string) $entry['source_id'], 12, '0', STR_PAD_LEFT))->values();
        $opening = (float) $entries->filter(fn (array $entry): bool => $entry['occurred_at'] < $from->toDateString())->sum(fn (array $entry): float => $entry['credit'] - $entry['debit']);
        $running = $opening;
        $rows = $entries->filter(fn (array $entry): bool => $entry['occurred_at'] >= $from->toDateString() && $entry['occurred_at'] <= $to->toDateString())->map(function (array $entry) use (&$running): array {
            $running += $entry['credit'] - $entry['debit'];
            return $entry + ['balance' => round($running, 6)];
        })->values();
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['opening_balance' => round($opening, 6), 'period_credits' => round((float) $rows->sum('credit'), 6), 'period_debits' => round((float) $rows->sum('debit'), 6), 'closing_balance' => round($running, 6)], 'meta' => ['supplier' => $supplier, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function customerCredit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'order_value' => ['nullable', 'numeric', 'min:0']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for customer credit assessment.');
        $customer = Customer::whereKey($id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        $assessment = app(CustomerCreditService::class)->assess($customer, $data['as_of'] ?? null);
        $orderValue = (float) ($data['order_value'] ?? 0);
        $reason = null;
        if ($customer->credit_hold) $reason = 'Customer is on credit hold.';
        elseif ((int) ($customer->credit_hold_after_days ?? 0) > 0 && $assessment['overdue_amount'] > 0.000001 && $assessment['oldest_overdue_days'] >= (int) $customer->credit_hold_after_days) $reason = 'Customer has overdue credit beyond the configured limit.';
        elseif ((float) $customer->credit_limit > 0 && $assessment['outstanding'] + $orderValue > (float) $customer->credit_limit + 0.000001) $reason = 'Customer credit limit would be exceeded.';
        return response()->json(['data' => ['customer' => $customer, 'assessment' => $assessment, 'order_value' => $orderValue, 'approval_eligible' => $reason === null, 'approval_block_reason' => $reason], 'meta' => ['as_of' => $data['as_of'] ?? now()->toDateString()]]);
    }

    private function agingBucket(int $days): string
    {
        return $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+')));
    }

    public function accounts(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        $accounts = ChartOfAccount::query()->where('is_active', true)->when($companyId, fn ($query, $id) => $query->where(fn ($scope) => $scope->where('company_id', $id)->orWhereNull('company_id')))->orderBy('code')->get(['id', 'company_id', 'parent_id', 'external_reference', 'code', 'name', 'account_type']);
        return response()->json(['data' => $accounts]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request, (int) $request->input('company_id') ?: null);
        abort_unless($companyId, 403, 'A company is required for account synchronization.');
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')], 'external_reference' => ['nullable', 'string', 'max:150'], 'parent_id' => ['nullable', 'integer', $owned('chart_of_accounts')], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'account_type' => ['required', 'in:asset,liability,equity,income,expense'], 'is_control_account' => ['nullable', 'boolean']]);
        if (!empty($data['external_reference']) && ChartOfAccount::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->exists()) return response()->json(['data' => ChartOfAccount::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first(), 'status' => 'duplicate_ignored']);
        if (ChartOfAccount::where('company_id', $companyId)->where('code', $data['code'])->exists()) return response()->json(['message' => 'This account code already exists.'], 422);
        if (!empty($data['parent_id']) && !ChartOfAccount::whereKey($data['parent_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('is_active', true)->exists()) return response()->json(['message' => 'The selected parent account is inactive or unauthorized.'], 422);
        $account = ChartOfAccount::create($data + ['company_id' => $companyId, 'is_control_account' => (bool) ($data['is_control_account'] ?? false), 'is_active' => true]);
        app(AuditService::class)->record('chart_of_account.created', $account, null, $account->toArray());
        return response()->json(['data' => $account, 'status' => 'created'], 201);
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        $account = ChartOfAccount::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'parent_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'account_type' => ['required', 'in:asset,liability,equity,income,expense'], 'is_control_account' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]);
        if (!empty($data['parent_id']) && (int) $data['parent_id'] === $account->id) return response()->json(['message' => 'An account cannot be its own parent.'], 422);
        if (!empty($data['parent_id']) && !ChartOfAccount::whereKey($data['parent_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('is_active', true)->exists()) return response()->json(['message' => 'The selected parent account is inactive or unauthorized.'], 422);
        if (!empty($data['parent_id'])) {
            $ancestorId = ChartOfAccount::whereKey($data['parent_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->value('parent_id'); $visited = [];
            while ($ancestorId !== null && !in_array((int) $ancestorId, $visited, true)) {
                if ((int) $ancestorId === $account->id) return response()->json(['message' => 'The selected parent would create an account hierarchy cycle.'], 422);
                $visited[] = (int) $ancestorId; $ancestorId = ChartOfAccount::whereKey($ancestorId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->value('parent_id');
            }
        }
        if (!empty($data['external_reference']) && ChartOfAccount::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->where('id', '<>', $account->id)->exists()) return response()->json(['message' => 'This external reference is already assigned.'], 422);
        if (ChartOfAccount::where('company_id', $companyId)->where('code', $data['code'])->where('id', '<>', $account->id)->exists()) return response()->json(['message' => 'This account code already exists.'], 422);
        if ($account->lines()->exists() && $data['account_type'] !== $account->account_type) return response()->json(['message' => 'The account type cannot change after journal lines exist.'], 422);
        $before = $account->toArray(); $account->update($data + ['is_control_account' => (bool) ($data['is_control_account'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('chart_of_account.updated', $account, $before, $account->fresh()->toArray());
        return response()->json(['data' => $account->fresh(), 'status' => 'updated']);
    }

    public function deactivateAccount(int $id): JsonResponse
    {
        $account = ChartOfAccount::where('company_id', $this->requestedCompanyId(request()))->findOrFail($id);
        if ($account->is_active && \App\Models\AccountMapping::where('account_id', $account->id)->exists()) return response()->json(['message' => 'Mapped control accounts cannot be deactivated.'], 422);
        if ($account->is_active) { $account->update(['is_active' => false]); app(AuditService::class)->record('chart_of_account.deactivated', $account, ['is_active' => true], ['is_active' => false]); }
        return response()->json(['data' => $account->fresh(), 'status' => 'deactivated']);
    }

    public function journals(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'updated_since' => ['nullable', 'date']]);
        $companyId = $this->requestedCompanyId($request, $data['company_id'] ?? null);
        $journals = JournalEntry::with('lines')->where('status', 'posted')->when($companyId, fn ($query, $id) => $query->where('company_id', $id))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($journals, $request, 'accounting.journals', 100);
    }

    public function reconciliation(Request $request, AccountingReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'date']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for accounting reconciliation.');
        $to = $data['to'] ?? now()->toDateString();
        $rows = collect($reconciliation->rows($companyId, $to));
        return response()->json([
            'data' => $rows,
            'summary' => ['status' => $rows->contains(fn (array $row): bool => $row['status'] === 'needs_mapping') ? 'needs_mapping' : ($rows->contains(fn (array $row): bool => $row['status'] === 'variance') ? 'variance' : 'reconciled'), 'variance_count' => $rows->where('status', 'variance')->count(), 'missing_mapping_count' => $rows->where('status', 'needs_mapping')->count()],
            'meta' => ['to' => $to],
        ]);
    }

    public function cogsReconciliation(Request $request, AccountingReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'tolerance' => ['nullable', 'numeric', 'min:0'],
        ]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for COGS reconciliation.');
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        return response()->json($reconciliation->cogs($companyId, $from, $to, $data['product_id'] ?? null, $data['location_id'] ?? null, (float) ($data['tolerance'] ?? 0.01)));
    }

    public function salesReconciliation(Request $request, AccountingReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'product_id' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'integer'], 'tolerance' => ['nullable', 'numeric', 'min:0']]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for sales reconciliation.');
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        return response()->json($reconciliation->sales($companyId, $from, $to, $data['product_id'] ?? null, $data['customer_id'] ?? null, (float) ($data['tolerance'] ?? 0.01)));
    }

    public function costCenterBudgets(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for budget synchronization.');
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'cost_center_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $budgets = CostCenterBudget::with(['costCenter:id,company_id,code,name', 'fiscalYear:id,name'])
            ->where('company_id', $companyId)
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['cost_center_id'] ?? null, fn ($query, $id) => $query->where('cost_center_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($budgets, $request, 'accounting.cost-center-budgets', (int) ($data['per_page'] ?? 50));
    }

    public function storeCostCenterBudget(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for budget synchronization.');
        $ownedCostCenter = Rule::exists('cost_centers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'cost_center_id' => ['required', 'integer', $ownedCostCenter],
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'budget_amount' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'], 'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = CostCenterBudget::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['costCenter', 'fiscalYear']), 'status' => 'duplicate_ignored']);
        }
        $overlap = CostCenterBudget::where('company_id', $companyId)->where('cost_center_id', $data['cost_center_id'])->where('is_active', true)
            ->where('period_start', '<=', $data['period_end'])->where('period_end', '>=', $data['period_start'])->exists();
        if ($overlap) return response()->json(['message' => 'An active budget for this cost center overlaps the requested period.'], 422);
        $budget = CostCenterBudget::create($data + ['company_id' => $companyId, 'is_active' => true, 'created_by' => $request->user()->id, 'currency_code' => strtoupper($data['currency_code'] ?? '') ?: null]);
        app(AuditService::class)->record('cost_center_budget.created', $budget, null, $budget->toArray());
        return response()->json(['data' => $budget->load(['costCenter', 'fiscalYear']), 'status' => 'created'], 201);
    }

    public function deactivateCostCenterBudget(Request $request, int $id): JsonResponse
    {
        $budget = CostCenterBudget::where('company_id', $this->requestedCompanyId($request))->findOrFail($id);
        if ($budget->is_active) {
            $budget->update(['is_active' => false]);
            app(AuditService::class)->record('cost_center_budget.deactivated', $budget, ['is_active' => true], ['is_active' => false]);
        }
        return response()->json(['data' => $budget->fresh(), 'status' => 'deactivated']);
    }

    public function costCenterBudgetVsActual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'cost_center_id' => ['nullable', 'integer', 'min:1'],
            'alert_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $companyId = $this->requestedCompanyId($request);
        abort_unless($companyId, 403, 'A company is required for budget reporting.');
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $threshold = array_key_exists('alert_threshold', $data) ? (float) $data['alert_threshold'] : 0.8;

        $budgets = CostCenterBudget::with('costCenter')
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('is_active', true)
            ->whereDate('period_end', '>=', $from)->whereDate('period_start', '<=', $to)
            ->when($data['cost_center_id'] ?? null, fn ($query, $id) => $query->where('cost_center_id', $id))
            ->get()->groupBy('cost_center_id');
        $lines = JournalLine::with('costCenter')->whereNotNull('cost_center_id')
            ->whereHas('entry', function ($query) use ($companyId, $from, $to): void {
                $query->where('status', 'posted')->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
            })->when($data['cost_center_id'] ?? null, fn ($query, $id) => $query->where('cost_center_id', $id))->get()
            ->groupBy('cost_center_id');
        $centerIds = $budgets->keys()->merge($lines->keys())->unique()->sort()->values();
        $rows = $centerIds->map(function ($centerId) use ($budgets, $lines, $threshold): array {
            $budgetRows = $budgets->get($centerId, collect());
            $actualLines = $lines->get($centerId, collect());
            $budget = (float) $budgetRows->sum('budget_amount');
            $debit = (float) $actualLines->sum('debit');
            $credit = (float) $actualLines->sum('credit');
            $actual = $debit - $credit;
            $utilization = $budget > 0 ? $actual / $budget : null;
            $status = $budget <= 0 ? ($actual > 0 ? 'unbudgeted' : 'no_budget') : ($actual > $budget ? 'over_budget' : ($utilization >= $threshold ? 'warning' : 'within_budget'));
            return [
                'cost_center' => $actualLines->first()?->costCenter ?: $budgetRows->first()?->costCenter,
                'budget' => round($budget, 6), 'debit' => round($debit, 6), 'credit' => round($credit, 6),
                'actual' => round($actual, 6), 'variance' => round($budget - $actual, 6),
                'utilization' => $utilization === null ? null : round($utilization, 6), 'status' => $status,
            ];
        })->values();
        return response()->json([
            'data' => $rows,
            'summary' => ['budget' => round((float) $rows->sum('budget'), 6), 'actual' => round((float) $rows->sum('actual'), 6), 'variance' => round((float) $rows->sum('variance'), 6), 'over_budget_count' => $rows->where('status', 'over_budget')->count(), 'warning_count' => $rows->where('status', 'warning')->count()],
            'meta' => ['from' => $from, 'to' => $to, 'alert_threshold' => $threshold, 'total' => $rows->count()],
        ]);
    }

    public function customerPayments(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:30'], 'allocation_status' => ['nullable', 'in:unallocated,partially_allocated,fully_allocated'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $payments = $this->companyScope(Payment::with(['customer', 'invoice', 'allAllocations.invoice']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('paid_status', $status))
            ->when($data['allocation_status'] ?? null, fn ($query, $status) => $query->where('allocation_status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($payments, $request, 'accounting.customer-payments', (int) ($data['per_page'] ?? 50));
    }

    public function supplierPayments(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'allocation_status' => ['nullable', 'in:unallocated,partially_allocated,fully_allocated'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $payments = $this->companyScope(SupplierPayment::with(['supplier', 'purchaseInvoice', 'allAllocations.invoice']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['allocation_status'] ?? null, fn ($query, $status) => $query->where('allocation_status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($payments, $request, 'accounting.supplier-payments', (int) ($data['per_page'] ?? 50));
    }

    public function customerRefunds(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'settlement_status' => ['nullable', 'in:pending,settled'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $refunds = $this->companyScope(CustomerRefund::with(['customer', 'settler', 'inventoryReturn.sourceInvoice', 'inventoryReturn.lines.product']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['settlement_status'] ?? null, fn ($query, $status) => $query->where('settlement_status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($refunds, $request, 'accounting.customer-refunds', (int) ($data['per_page'] ?? 50));
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request, (int) $request->input('company_id') ?: null);
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')], 'external_reference' => ['nullable', 'string', 'max:150'], 'entry_no' => ['nullable', 'string', 'max:80'], 'date' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000'], 'consolidation_elimination' => ['nullable', 'boolean'], 'consolidation_reference' => ['nullable', 'string', 'max:150'], 'intercompany_reference' => ['nullable', 'string', 'max:150'], 'counterparty_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')], 'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'integer', $owned('chart_of_accounts')], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.currency_code' => ['nullable', 'string', 'size:3'], 'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'lines.*.department_id' => ['nullable', 'integer', $owned('departments')], 'lines.*.cost_center_id' => ['nullable', 'integer', $owned('cost_centers')], 'lines.*.description' => ['nullable', 'string', 'max:1000']]);
        if (!empty($data['consolidation_elimination']) && !Company::whereKey($companyId)->whereHas('subsidiaries', fn ($query) => $query->where('is_active', true))->exists()) abort(422, 'Consolidation eliminations can only be posted by a parent company with active subsidiaries.');
        if (!empty($data['counterparty_company_id']) && (int) $data['counterparty_company_id'] === (int) $companyId) abort(422, 'An intercompany journal counterparty must be a different company.');
        if (!empty($data['external_reference'])) {
            $existing = JournalEntry::where('external_reference', $data['external_reference'])->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })->with('lines')->first();
            if ($existing) return response()->json(['data' => $existing, 'idempotent' => true]);
        }
        try {
            $entry = app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => $data['entry_no'] ?? 'EXT-'.now()->format('YmdHis').'-'.random_int(100, 999), 'external_reference' => $data['external_reference'] ?? null, 'date' => $data['date'], 'description' => $data['description'] ?? 'External accounting journal', 'consolidation_elimination' => (bool) ($data['consolidation_elimination'] ?? false), 'consolidation_reference' => $data['consolidation_reference'] ?? null, 'intercompany_reference' => $data['intercompany_reference'] ?? null, 'counterparty_company_id' => $data['counterparty_company_id'] ?? null], $data['lines']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($entry->consolidation_elimination) app(AuditService::class)->record('journal.consolidation_elimination.created', $entry, null, $entry->toArray());
        return response()->json(['data' => $entry, 'idempotent' => false], 201);
    }

    public function storeConsolidationElimination(Request $request): JsonResponse
    {
        $request->merge(['consolidation_elimination' => true]);
        return $this->storeJournal($request);
    }

    public function reverseJournal(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $journal = JournalEntry::findOrFail($id);
        try {
            $reversal = app(AccountingService::class)->reverse($journal, $data['reason'] ?? null);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        app(AuditService::class)->record('journal.reversed', $journal, ['status' => 'posted'], ['status' => 'reversed', 'reversal_id' => $reversal->id]);
        return response()->json(['data' => $reversal, 'reversed_journal_id' => $journal->id], 201);
    }

    private function requestedCompanyId(Request $request, ?int $requested = null): ?int
    {
        $userCompanyId = $request->user()?->company_id;
        $requested ??= ((int) $request->input('company_id')) ?: null;
        if ($userCompanyId && $requested && (int) $userCompanyId !== (int) $requested) abort(403, 'Token is not authorized for this company.');
        return $userCompanyId ? (int) $userCompanyId : $requested;
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
