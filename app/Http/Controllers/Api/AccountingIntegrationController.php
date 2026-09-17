<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\CustomerRefund;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPaymentAllocation;
use App\Services\AccountingService;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\CustomerCreditService;
use App\Services\SupplierPayablesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingIntegrationController extends Controller
{
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
        $page = max(1, $request->integer('page', 1)); $perPage = (int) ($data['per_page'] ?? 50); $bucketSummary = $rows->groupBy('bucket')->map(fn ($bucketRows) => (float) $bucketRows->sum('outstanding'));
        return response()->json(['data' => $rows->sortByDesc('days_overdue')->forPage($page, $perPage)->values(), 'summary' => ['total' => (float) $rows->sum('outstanding'), 'current' => (float) ($bucketSummary['current'] ?? 0), '1-30' => (float) ($bucketSummary['1-30'] ?? 0), '31-60' => (float) ($bucketSummary['31-60'] ?? 0), '61-90' => (float) ($bucketSummary['61-90'] ?? 0), '90+' => (float) ($bucketSummary['90+'] ?? 0)], 'meta' => ['as_of' => $asOf, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function customerAging(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'customer_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->requestedCompanyId($request); abort_unless($companyId, 403, 'A company is required for receivables aging.');
        $companyScope = fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
        $asOf = $data['as_of'] ?? now()->toDateString();
        $invoices = Invoice::with('customer')->where($companyScope)->where('status', 1)->whereNotNull('customer_id')->whereDate('date', '<=', $asOf)->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))->get();
        $invoiceIds = $invoices->pluck('id');
        $paid = Payment::where($companyScope)->whereIn('invoice_id', $invoiceIds)->where('is_reversed', false)->whereDate('created_at', '<=', $asOf)->selectRaw('invoice_id, COALESCE(SUM(paid_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $allocated = CustomerPaymentAllocation::where($companyScope)->whereIn('invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOf)->whereHas('payment', fn ($q) => $q->where('is_reversed', false))->selectRaw('invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $rows = $invoices->map(function (Invoice $invoice) use ($paid, $allocated, $asOf): ?array {
            $outstanding = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0)); if ($outstanding <= 0.000001) return null;
            $dueDate = \Carbon\Carbon::parse($invoice->date ?? $invoice->created_at)->addDays((int) ($invoice->customer?->credit_days ?? 0));
            $days = max(0, $dueDate->diffInDays(\Carbon\Carbon::parse($asOf), false));
            return ['customer' => $invoice->customer, 'invoice' => $invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $this->agingBucket($days), 'outstanding' => $outstanding, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($invoice->customer ?: new \App\Models\Customer(), $outstanding, $days)];
        })->filter()->values();
        $legacyIds = $invoiceIds->all() ?: [-1];
        $legacy = Payment::with(['customer', 'invoice'])->where($companyScope)->where('due_amount', '>', 0)->whereNotIn('invoice_id', $legacyIds)->whereDate('created_at', '<=', $asOf)->get()->map(function (Payment $payment) use ($asOf): array {
            $date = $payment->invoice?->date ?? $payment->created_at; $dueDate = \Carbon\Carbon::parse($date)->addDays((int) ($payment->customer?->credit_days ?? 0)); $days = max(0, $dueDate->diffInDays(\Carbon\Carbon::parse($asOf), false));
            return ['customer' => $payment->customer, 'invoice' => $payment->invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $this->agingBucket($days), 'outstanding' => (float) $payment->due_amount, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($payment->customer ?: new \App\Models\Customer(), (float) $payment->due_amount, $days)];
        });
        $rows = $rows->concat($legacy)->filter(fn (array $row) => !($data['customer_id'] ?? null) || (int) ($row['customer']->id ?? 0) === (int) $data['customer_id'])->sortByDesc('days_overdue')->values();
        $page = max(1, $request->integer('page', 1)); $perPage = (int) ($data['per_page'] ?? 50); $bucketSummary = $rows->groupBy('bucket')->map(fn ($bucketRows) => (float) $bucketRows->sum('outstanding'));
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['total' => (float) $rows->sum('outstanding'), 'current' => (float) ($bucketSummary['current'] ?? 0), '1-30' => (float) ($bucketSummary['1-30'] ?? 0), '31-60' => (float) ($bucketSummary['31-60'] ?? 0), '61-90' => (float) ($bucketSummary['61-90'] ?? 0), '90+' => (float) ($bucketSummary['90+'] ?? 0)], 'meta' => ['as_of' => $asOf, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
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
        Payment::where($scope)->where('customer_id', $customer->id)->where(function ($query): void { $query->where('is_reversed', false)->orWhereNull('is_reversed'); })->whereDate('created_at', '<=', $to->toDateString())->with(['allocations' => fn ($query) => $query->whereDate('allocated_at', '<=', $to->toDateString())])->get()->each(function (Payment $payment) use ($entries): void {
            $entries->push(['occurred_at' => $payment->created_at->toDateString(), 'type' => 'payment', 'reference' => $payment->reference ?: 'PAY-'.$payment->id, 'source_id' => $payment->id, 'debit' => 0.0, 'credit' => (float) $payment->paid_amount, 'currency_code' => $payment->currency_code, 'allocated_amount' => (float) $payment->allocations->sum('amount'), 'allocated_invoice_amount' => (float) $payment->allocations->sum('amount'), 'allocated_payment_amount' => (float) $payment->allocations->sum(fn ($allocation): float => (float) ($allocation->payment_amount ?? $allocation->amount)), 'allocation_count' => $payment->allocations->count()]);
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
        $companyId = $this->requestedCompanyId($request, $request->integer('company_id') ?: null);
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
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $refunds = $this->companyScope(CustomerRefund::with(['customer', 'inventoryReturn.sourceInvoice', 'inventoryReturn.lines.product']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($refunds, $request, 'accounting.customer-refunds', (int) ($data['per_page'] ?? 50));
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $companyId = $this->requestedCompanyId($request, $request->integer('company_id') ?: null);
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')], 'external_reference' => ['nullable', 'string', 'max:150'], 'entry_no' => ['nullable', 'string', 'max:80'], 'date' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'integer', $owned('chart_of_accounts')], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.currency_code' => ['nullable', 'string', 'size:3'], 'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'lines.*.department_id' => ['nullable', 'integer', $owned('departments')], 'lines.*.cost_center_id' => ['nullable', 'integer', $owned('cost_centers')], 'lines.*.description' => ['nullable', 'string', 'max:1000']]);
        if (!empty($data['external_reference'])) {
            $existing = JournalEntry::where('external_reference', $data['external_reference'])->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })->with('lines')->first();
            if ($existing) return response()->json(['data' => $existing, 'idempotent' => true]);
        }
        try {
            $entry = app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => $data['entry_no'] ?? 'EXT-'.now()->format('YmdHis').'-'.random_int(100, 999), 'external_reference' => $data['external_reference'] ?? null, 'date' => $data['date'], 'description' => $data['description'] ?? 'External accounting journal'], $data['lines']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $entry, 'idempotent' => false], 201);
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
