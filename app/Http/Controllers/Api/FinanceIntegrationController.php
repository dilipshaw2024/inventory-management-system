<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\TaxRate;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\InvoiceDetail;
use App\Models\PurchaseInvoiceLine;
use App\Models\InventoryReturn;
use App\Models\AccountMapping;
use App\Models\JournalLine;
use App\Models\ChartOfAccount;
use App\Models\TaxSettlement;
use App\Models\TaxFiling;
use App\Models\ProductClassification;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\FinancialReportingService;
use App\Services\ConsolidatedReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceIntegrationController extends Controller
{
    public function trialBalance(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for trial balance reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'account_id' => ['nullable', 'integer']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $accounts = ChartOfAccount::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->when($data['account_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->orderBy('code')->get();
        $lines = JournalLine::with('entry')->whereHas('entry', fn ($query) => $query->where('status', 'posted')->whereDate('date', '<=', $to)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->groupBy('account_id');
        $rows = $accounts->map(function (ChartOfAccount $account) use ($lines, $from, $to): array {
            $accountLines = $lines->get($account->id, collect());
            $opening = $accountLines->filter(fn ($line): bool => $line->entry->date->toDateString() < $from);
            $period = $accountLines->filter(fn ($line): bool => $line->entry->date->toDateString() >= $from && $line->entry->date->toDateString() <= $to);
            $openingDebit = (float) $opening->sum('debit'); $openingCredit = (float) $opening->sum('credit'); $periodDebit = (float) $period->sum('debit'); $periodCredit = (float) $period->sum('credit');
            return ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type, 'opening_debit' => $openingDebit, 'opening_credit' => $openingCredit, 'period_debit' => $periodDebit, 'period_credit' => $periodCredit, 'closing_debit' => $openingDebit + $periodDebit, 'closing_credit' => $openingCredit + $periodCredit, 'net_balance' => ($openingDebit + $periodDebit) - ($openingCredit + $periodCredit)];
        })->filter(fn (array $row): bool => abs($row['closing_debit']) > 0.000001 || abs($row['closing_credit']) > 0.000001)->values();
        return response()->json(['data' => $rows, 'summary' => ['opening_debit' => (float) $rows->sum('opening_debit'), 'opening_credit' => (float) $rows->sum('opening_credit'), 'period_debit' => (float) $rows->sum('period_debit'), 'period_credit' => (float) $rows->sum('period_credit'), 'closing_debit' => (float) $rows->sum('closing_debit'), 'closing_credit' => (float) $rows->sum('closing_credit'), 'net_balance' => (float) $rows->sum('net_balance')], 'meta' => ['from' => $from, 'to' => $to, 'accounts' => $rows->count()]]);
    }

    public function financialStatements(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for financial statements.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'account_id' => ['nullable', 'integer']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $report = app(FinancialReportingService::class)->statements((int) $companyId, $from, $to, isset($data['account_id']) ? (int) $data['account_id'] : null);
        return response()->json(['data' => ['profit_and_loss' => $report['profit_and_loss'], 'balance_sheet' => $report['balance_sheet']], 'summary' => $report['summary'], 'meta' => ['from' => $from, 'to' => $to, 'accounts' => $report['profit_and_loss']->count() + $report['balance_sheet']->count()]]);
    }

    public function consolidatedTrialBalance(Request $request, ConsolidatedReportingService $service): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for consolidated reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'reporting_currency' => ['nullable', 'string', 'size:3']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        try { $report = $service->trialBalance((int) $companyId, $from, $to, $data['reporting_currency'] ?? null); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $report['rows'], 'summary' => $report['summary'], 'meta' => ['from' => $from, 'to' => $to, 'reporting_currency' => $report['reporting_currency'], 'companies' => $report['companies']->map(fn ($company): array => ['id' => $company->id, 'name' => $company->name, 'base_currency' => $company->base_currency])->values(), 'exchange_rates' => $report['rates'], 'intercompany_eliminations' => $this->eliminationStatus($report), 'elimination_journal_count' => $report['elimination_journal_count'], 'automatic_elimination_journal_count' => $report['automatic_elimination_journal_count']]]);
    }

    public function consolidatedFinancialStatements(Request $request, ConsolidatedReportingService $service): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for consolidated reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'reporting_currency' => ['nullable', 'string', 'size:3']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        try { $report = $service->financialStatements((int) $companyId, $from, $to, $data['reporting_currency'] ?? null); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => ['profit_and_loss' => $report['profit_and_loss'], 'balance_sheet' => $report['balance_sheet']], 'summary' => $report['statement_summary'], 'meta' => ['from' => $from, 'to' => $to, 'reporting_currency' => $report['reporting_currency'], 'companies' => $report['companies']->map(fn ($company): array => ['id' => $company->id, 'name' => $company->name, 'base_currency' => $company->base_currency])->values(), 'exchange_rates' => $report['rates'], 'intercompany_eliminations' => $this->eliminationStatus($report), 'elimination_journal_count' => $report['elimination_journal_count'], 'automatic_elimination_journal_count' => $report['automatic_elimination_journal_count']]]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for cash-flow reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $report = app(FinancialReportingService::class)->cashFlow((int) $companyId, $from, $to);
        return response()->json(['data' => $report['rows'], 'summary' => $report['summary'], 'meta' => ['from' => $from, 'to' => $to, 'cash_accounts' => $report['cash_accounts']->pluck('id')->values(), 'configured' => $report['cash_accounts']->isNotEmpty()]]);
    }

    public function taxReport(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax reporting.');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'classification_id' => ['nullable', 'integer', Rule::exists('product_classifications', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]]);
        $from = $data['from']; $to = $data['to']; $jurisdiction = $data['jurisdiction'] ?? null;
        $classificationId = isset($data['classification_id']) ? (int) $data['classification_id'] : null;
        $taxKey = static fn (float $rate, ?string $jurisdiction, $classification): string => number_format($rate, 4, '.', '').'|'.($jurisdiction ?: 'UNSPECIFIED').'|'.($classification ? $classification->scheme.':'.$classification->code : 'UNCLASSIFIED');
        $sales = InvoiceDetail::with(['invoice', 'product.classification'])->whereHas('invoice', fn ($query) => $query->whereIn('status', [1, 'approved'])->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->when($classificationId, fn ($query) => $query->whereHas('product', fn ($product) => $product->where('classification_id', $classificationId)))->get()->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction, $line->product?->classification));
        $returns = InventoryReturn::with('lines.product.classification')->where('return_type', 'sales')->where('status', 'approved')->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($query) => $query->where('tax_jurisdiction', $jurisdiction))->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line): array => ['return' => $return, 'line' => $line]))->filter(fn (array $item): bool => !$classificationId || (int) ($item['line']->product?->classification_id ?? 0) === (int) $classificationId)->groupBy(fn (array $item): string => $taxKey((float) ($item['line']->tax_rate ?? 0), $item['return']->tax_jurisdiction, $item['line']->product?->classification));
        $purchases = PurchaseInvoiceLine::with(['invoice', 'product.classification'])->whereHas('invoice', fn ($query) => $query->where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->when($classificationId, fn ($query) => $query->whereHas('product', fn ($product) => $product->where('classification_id', $classificationId)))->get()->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction, $line->product?->classification));
        $rates = $sales->keys()->merge($returns->keys())->merge($purchases->keys())->unique()->sort()->values();
        $rows = $rates->map(function ($rate) use ($sales, $returns, $purchases): array {
            $sale = $sales->get($rate, collect()); $return = $returns->get($rate, collect()); $purchase = $purchases->get($rate, collect());
            $returnTaxable = (float) $return->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0));
            $returnTax = (float) $return->sum(fn (array $item): float => $item['return']->tax_exempt ? 0 : (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0) * (float) ($item['line']->tax_rate ?? 0) / 100);
            $salesTaxable = (float) $sale->sum(fn ($line): float => (float) $line->selling_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0)) - $returnTaxable;
            $salesTax = (float) $sale->sum('tax_amount') - $returnTax;
            $purchaseTaxable = (float) $purchase->sum(fn ($line): float => (float) $line->quantity * (float) $line->unit_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0));
            $purchaseTax = (float) $purchase->sum('tax_amount');
            [$rateValue, $jurisdiction] = explode('|', $rate, 3);
            $returnClassification = $return->isNotEmpty() ? $return->first()['line']->product?->classification : null;
            $classification = $sale->first()?->product?->classification ?: $purchase->first()?->product?->classification ?: $returnClassification;
            return ['rate' => (float) $rateValue, 'jurisdiction' => $jurisdiction === 'UNSPECIFIED' ? null : $jurisdiction, 'classification' => $classification ? ['id' => $classification->id, 'scheme' => $classification->scheme, 'code' => $classification->code, 'jurisdiction' => $classification->jurisdiction] : null, 'sales' => ['taxable' => $salesTaxable, 'tax' => $salesTax, 'documents' => $sale->pluck('invoice_id')->unique()->count(), 'return_documents' => $return->pluck('return.id')->unique()->count()], 'purchases' => ['taxable' => $purchaseTaxable, 'tax' => $purchaseTax, 'documents' => $purchase->pluck('purchase_invoice_id')->unique()->count()], 'net_tax' => $salesTax - $purchaseTax];
        })->values();
        return response()->json(['data' => $rows, 'summary' => ['sales_tax' => (float) $rows->sum('sales.tax'), 'purchase_tax' => (float) $rows->sum('purchases.tax'), 'net_tax' => (float) $rows->sum('net_tax'), 'sales_documents' => (int) $rows->sum('sales.documents'), 'return_documents' => (int) $rows->sum('sales.return_documents'), 'purchase_documents' => (int) $rows->sum('purchases.documents')], 'meta' => ['from' => $from, 'to' => $to, 'jurisdiction' => $jurisdiction, 'classification_id' => $classificationId, 'rates' => $rows->count()]]);
    }

    public function taxReconciliation(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax reconciliation.');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'tolerance' => ['nullable', 'numeric', 'min:0', 'max:1000']]);
        $taxReport = $this->taxReport($request);
        $report = json_decode($taxReport->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $accountIds = AccountMapping::whereIn('mapping_key', ['tax_payable', 'sales_tax', 'input_tax', 'tax_receivable'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('company_id IS NULL')->pluck('account_id')->unique()->values();
        $posted = $accountIds->isEmpty() ? 0.0 : (float) JournalLine::whereIn('account_id', $accountIds)
            ->whereHas('entry', fn ($query) => $query->where('company_id', $companyId)->where('status', 'posted')->whereBetween('date', [$data['from'], $data['to']]))
            ->sum(DB::raw('credit - debit'));
        $expected = (float) ($report['summary']['net_tax'] ?? 0);
        $difference = round($expected - $posted, 6);
        $tolerance = (float) ($data['tolerance'] ?? 0.01);
        return response()->json([
            'data' => $report['data'],
            'summary' => ($report['summary'] ?? []) + [
                'expected_net_tax' => $expected,
                'posted_tax_control_net' => round($posted, 6),
                'variance' => $difference,
                'tolerance' => $tolerance,
                'status' => abs($difference) <= $tolerance ? 'reconciled' : 'variance',
                'mapped_tax_accounts' => $accountIds->all(),
            ],
            'meta' => ($report['meta'] ?? []) + ['reconciled_from' => $data['from'], 'reconciled_to' => $data['to']],
        ]);
    }

    public function taxFilings(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax filings.');
        $data = $request->validate(['status' => ['nullable', 'in:draft,submitted,accepted,rejected'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $filings = TaxFiling::where('company_id', $companyId)->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($filings, $request, 'accounting.tax-filings', (int) ($data['per_page'] ?? 50));
    }

    public function storeTaxFiling(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax filings.');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'external_reference' => ['nullable', 'string', 'max:180'], 'return_type' => ['nullable', 'string', 'max:40']]);
        $report = json_decode($this->taxReport($request)->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $externalReference = $data['external_reference'] ?? 'tax-filing:'.$companyId.':'.$data['from'].':'.$data['to'].':'.($data['jurisdiction'] ?? 'all');
        $existing = TaxFiling::where('company_id', $companyId)->where('external_reference', $externalReference)->exists();
        $filing = app(\App\Services\TaxFilingService::class)->createSnapshot((int) $companyId, $data['from'], $data['to'], $data['jurisdiction'] ?? null, $report, $data['external_reference'] ?? null, $data['return_type'] ?? 'indirect_tax');
        return response()->json(['data' => $filing, 'status' => $existing ? 'existing' : 'draft', 'tax_report' => $report['summary'] ?? []], $existing ? 200 : 201);
    }

    public function verifyTaxFiling(Request $request, int $id): JsonResponse
    {
        $filing = TaxFiling::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $verified = app(\App\Services\TaxFilingService::class)->verifySnapshot($filing);
        return response()->json(['data' => ['id' => $filing->id, 'snapshot_hash' => $filing->snapshot_hash, 'verified' => $verified], 'status' => $verified ? 'verified' : 'tampered']);
    }

    public function exportTaxFiling(Request $request, int $id)
    {
        $filing = TaxFiling::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $format = $request->validate(['format' => ['nullable', 'in:json,csv']])['format'] ?? 'json';
        $service = app(\App\Services\TaxFilingService::class);
        abort_unless($service->verifySnapshot($filing), 409, 'Tax filing snapshot integrity verification failed.');
        $snapshot = $filing->snapshot_payload;
        $package = [
            'filing' => $filing->only(['id', 'filing_no', 'external_reference', 'period_from', 'period_to', 'jurisdiction', 'return_type', 'status', 'snapshot_hash']),
            'snapshot' => $snapshot,
            'integrity' => ['algorithm' => 'sha256', 'verified' => true],
        ];
        if ($format === 'json') return response()->json(['data' => $package, 'status' => 'exported']);
        $rows = $snapshot['report']['data'] ?? [];
        $summary = $snapshot['report']['summary'] ?? [];
        return response()->streamDownload(function () use ($rows, $summary, $filing): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['filing_no', 'period_from', 'period_to', 'jurisdiction', 'rate', 'classification_scheme', 'classification_code', 'sales_taxable', 'sales_tax', 'purchase_taxable', 'purchase_tax', 'net_tax']);
            foreach ($rows as $row) {
                fputcsv($handle, [$filing->filing_no, $filing->period_from?->toDateString(), $filing->period_to?->toDateString(), $row['jurisdiction'] ?? $filing->jurisdiction, $row['rate'] ?? null, $row['classification']['scheme'] ?? null, $row['classification']['code'] ?? null, $row['sales']['taxable'] ?? 0, $row['sales']['tax'] ?? 0, $row['purchases']['taxable'] ?? 0, $row['purchases']['tax'] ?? 0, $row['net_tax'] ?? 0]);
            }
            fputcsv($handle, ['SUMMARY', '', '', '', '', '', '', '', $summary['sales_tax'] ?? 0, '', $summary['purchase_tax'] ?? 0, $summary['net_tax'] ?? 0]);
            fclose($handle);
        }, $filing->filing_no.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function submitTaxFiling(Request $request, int $id): JsonResponse
    {
        $filing = TaxFiling::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $data = $request->validate(['filing_reference' => ['required', 'string', 'max:180']]);
        try { $filing = app(\App\Services\TaxFilingService::class)->submit($filing, $data['filing_reference']); }
        catch (\RuntimeException $exception) { abort(422, $exception->getMessage()); }
        app(AuditService::class)->record('tax_filing.submitted', $filing, ['status' => 'draft'], $filing->toArray());
        return response()->json(['data' => $filing, 'status' => 'submitted']);
    }

    public function decideTaxFiling(Request $request, int $id): JsonResponse
    {
        $filing = TaxFiling::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'rejection_reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:3000']]);
        try { $filing = app(\App\Services\TaxFilingService::class)->decide($filing, $data['status'], $data['rejection_reason'] ?? null); }
        catch (\RuntimeException $exception) { abort(422, $exception->getMessage()); }
        app(AuditService::class)->record('tax_filing.'.$data['status'], $filing, ['status' => 'submitted'], $filing->toArray());
        return response()->json(['data' => $filing, 'status' => $data['status']]);
    }

    public function settleTax(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax settlement.');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'paid_at' => ['required', 'date'], 'payment_reference' => ['nullable', 'string', 'max:150']]);
        $externalReference = 'tax-settlement:'.$companyId.':'.$data['from'].':'.$data['to'].':'.($data['jurisdiction'] ?? 'all');
        $alreadySettled = TaxSettlement::where('company_id', $companyId)->where('external_reference', $externalReference)->exists();
        $report = json_decode($this->taxReport($request)->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $netTax = (float) ($report['summary']['net_tax'] ?? 0);
        try {
            $settlement = app(\App\Services\TaxSettlementService::class)->settle((int) $companyId, $data['from'], $data['to'], $data['jurisdiction'] ?? null, $netTax, $data['paid_at'], $data['payment_reference'] ?? null);
        } catch (\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }
        app(AuditService::class)->record('tax_settlement.posted', $settlement, null, $settlement->toArray());
        return response()->json(['data' => $settlement, 'status' => 'posted', 'tax_report' => $report['summary']], $alreadySettled ? 200 : 201);
    }

    public function fiscalYears(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['status' => ['nullable', 'in:open,closed'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $years = $this->companyScope(FiscalYear::query(), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($years, $request, 'accounting.fiscal-years', (int) ($data['per_page'] ?? 50));
    }

    public function storeFiscalYear(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        if (!$companyId) abort(422, 'A company is required for fiscal years.');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]);
        if (FiscalYear::where('company_id', $companyId)->where(function ($query) use ($data): void {
            $query->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on']);
        })->exists()) abort(422, 'The fiscal-year period overlaps an existing period.');
        $year = DB::transaction(function () use ($data, $companyId): FiscalYear {
            $year = FiscalYear::create($data + ['company_id' => $companyId, 'status' => 'open']);
            app(AuditService::class)->record('fiscal_year.created', $year, null, $year->toArray());
            return $year;
        });
        return response()->json(['data' => $year, 'status' => 'created'], 201);
    }

    public function closeFiscalYear(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['close_reason' => ['required', 'string', 'max:2000']]);
        try {
            $year = DB::transaction(function () use ($id, $data): FiscalYear {
                $year = $this->companyScope(FiscalYear::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($year->status !== 'open') throw new \RuntimeException('Only open fiscal years can be closed.');
                $checks = [
                    [\App\Models\PurchaseOrder::class, 'submitted', 'purchase orders'], [\App\Models\SalesOrder::class, 'submitted', 'sales orders'],
                    [\App\Models\GoodsReceipt::class, 'pending', 'goods receipts'], [\App\Models\Delivery::class, 'pending', 'deliveries'],
                    [\App\Models\InventoryAdjustment::class, 'pending', 'inventory adjustments'], [\App\Models\InventoryTransfer::class, 'pending', 'inventory transfers'],
                    [\App\Models\InventoryReturn::class, 'pending', 'inventory returns'], [\App\Models\StockCount::class, 'submitted', 'stock counts'],
                    [\App\Models\InventoryStatusTransfer::class, 'pending', 'status transfers'], [\App\Models\PurchaseInvoice::class, 'pending', 'purchase invoices'],
                    [\App\Models\SupplierPayment::class, 'pending', 'supplier payments'], [\App\Models\CustomerRefund::class, 'pending', 'customer refunds'],
                    [\App\Models\JournalEntry::class, 'draft', 'manual journals'],
                ];
                $pending = collect($checks)->mapWithKeys(fn (array $check): array => [$check[2] => $this->companyScope($check[0]::query(), $year->company_id)->where('status', $check[1])->count()])->filter(fn (int $count): bool => $count > 0);
                if ($pending->isNotEmpty()) throw new \RuntimeException('Close checklist failed: '.$pending->map(fn (int $count, string $label): string => $count.' '.$label)->implode('; ').'.');
                app(\App\Services\FiscalPeriodService::class)->assertBankReconciliationsClosed((int) $year->company_id, $year->ends_on->toDateString());
                $snapshot = app(\App\Services\InventorySnapshotService::class)->capture((int) $year->company_id, $year->ends_on, auth()->id());
                if ($snapshot->status === 'variance') throw new \RuntimeException('Close checklist failed: inventory snapshot #'.$snapshot->id.' contains negative-balance variances. Resolve the inventory reconciliation before closing.');
                $before = $year->only(['status', 'closed_at', 'closed_by', 'inventory_snapshot_id']);
                $year->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'inventory_snapshot_id' => $snapshot->id]);
                app(AuditService::class)->record('fiscal_year.closed', $year, $before, $year->toArray() + ['close_reason' => $data['close_reason']]);
                return $year->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $year, 'status' => $year->status]);
    }

    public function reopenFiscalYear(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['reopen_reason' => ['required', 'string', 'max:2000']]);
        $year = $this->companyScope(FiscalYear::query(), $request->user()?->company_id)->findOrFail($id);
        if ($year->status !== 'closed') abort(422, 'Only closed fiscal years can be reopened.');
        $before = $year->only(['status', 'closed_at', 'closed_by']);
        $year->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        app(AuditService::class)->record('fiscal_year.reopened', $year, $before, $year->toArray() + ['reopen_reason' => $data['reopen_reason']]);
        return response()->json(['data' => $year->fresh(), 'status' => $year->status]);
    }

    public function fiscalPeriods(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for fiscal periods.');
        $data = $request->validate(['fiscal_year_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,closed'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $periods = FiscalPeriod::with('fiscalYear')->where('company_id', $companyId)
            ->when($data['fiscal_year_id'] ?? null, fn ($query, $id) => $query->where('fiscal_year_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($periods, $request, 'accounting.fiscal-periods', (int) ($data['per_page'] ?? 50));
    }

    public function storeFiscalPeriod(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for fiscal periods.');
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'open'))],
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $year = FiscalYear::where('company_id', $companyId)->whereKey($data['fiscal_year_id'])->firstOrFail();
        if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) abort(422, 'Fiscal periods must be contained within their fiscal year.');
        if (FiscalPeriod::where('company_id', $companyId)->where('fiscal_year_id', $year->id)->where(function ($query) use ($data): void {
            $query->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on']);
        })->exists()) abort(422, 'The fiscal period overlaps an existing period.');
        $period = FiscalPeriod::create($data + ['company_id' => $companyId, 'status' => 'open']);
        app(AuditService::class)->record('fiscal_period.created', $period, null, $period->toArray());
        return response()->json(['data' => $period->load('fiscalYear'), 'status' => 'created'], 201);
    }

    public function fiscalPeriodCloseChecklist(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for fiscal-period close checks.');
        $period = FiscalPeriod::with('fiscalYear')->where('company_id', $companyId)->findOrFail($id);
        $endDate = $period->ends_on->toDateString();
        $unmatchedBankLines = \App\Models\BankStatementLine::where('company_id', $companyId)
            ->whereDate('transaction_date', '<=', $endDate)->where('status', 'unmatched')->count();
        $draftReconciliations = \App\Models\BankReconciliation::where('company_id', $companyId)
            ->whereDate('statement_date', '<=', $endDate)->where('status', 'draft')->count();
        $pendingRevaluations = \App\Models\InventoryCostRevaluationRun::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'pending')->whereDate('as_of_date', '<=', $endDate)->count();
        $snapshot = \App\Models\InventoryReconciliationSnapshot::where('company_id', $companyId)
            ->whereDate('as_of_date', $endDate)->latest('id')->first();
        $checks = [
            ['key' => 'bank_statement_lines', 'label' => 'Bank statement lines matched', 'passed' => $unmatchedBankLines === 0, 'count' => $unmatchedBankLines],
            ['key' => 'bank_reconciliations', 'label' => 'Bank reconciliations finalized', 'passed' => $draftReconciliations === 0, 'count' => $draftReconciliations],
            ['key' => 'inventory_revaluations', 'label' => 'Inventory cost revaluations completed', 'passed' => $pendingRevaluations === 0, 'count' => $pendingRevaluations],
            ['key' => 'inventory_snapshot', 'label' => 'Inventory snapshot ready or capturable', 'passed' => !$snapshot || $snapshot->status === 'balanced', 'count' => $snapshot ? 1 : 0, 'status' => $snapshot?->status ?? 'will_capture_on_close'],
        ];
        $passed = collect($checks)->every(fn (array $check): bool => $check['passed']);
        return response()->json(['data' => ['period' => $period, 'checks' => $checks, 'ready_to_close' => $passed], 'status' => $passed ? 'ready' : 'blocked']);
    }

    public function closeFiscalPeriod(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['close_reason' => ['required', 'string', 'max:2000']]);
        try {
            $period = DB::transaction(function () use ($id, $data, $request): FiscalPeriod {
                $period = FiscalPeriod::where('company_id', $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($period->status !== 'open') throw new \RuntimeException('Only open fiscal periods can be closed.');
                $year = $period->fiscalYear()->lockForUpdate()->first();
                if (!$year || $year->status !== 'open') throw new \RuntimeException('The parent fiscal year must be open.');
                app(\App\Services\FiscalPeriodService::class)->assertBankReconciliationsClosed((int) $period->company_id, $period->ends_on->toDateString());
                app(\App\Services\FiscalPeriodService::class)->assertCostRevaluationsClosed((int) $period->company_id, $period->ends_on->toDateString());
                $snapshot = app(\App\Services\InventorySnapshotService::class)->capture((int) $period->company_id, $period->ends_on, $request->user()?->id);
                if ($snapshot->status === 'variance') throw new \RuntimeException('The fiscal-period close failed because the inventory snapshot contains negative-balance variances.');
                $before = $period->toArray();
                $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()?->id, 'inventory_snapshot_id' => $snapshot->id, 'close_reason' => $data['close_reason']]);
                app(AuditService::class)->record('fiscal_period.closed', $period, $before, $period->fresh()->toArray());
                return $period->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $period->load('fiscalYear', 'inventorySnapshot'), 'status' => $period->status]);
    }

    public function reopenFiscalPeriod(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['reopen_reason' => ['required', 'string', 'max:2000']]);
        $period = FiscalPeriod::where('company_id', $request->user()?->company_id)->findOrFail($id);
        if ($period->status !== 'closed') abort(422, 'Only closed fiscal periods can be reopened.');
        $before = $period->toArray();
        $period->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        app(AuditService::class)->record('fiscal_period.reopened', $period, $before, $period->toArray() + ['reopen_reason' => $data['reopen_reason']]);
        return response()->json(['data' => $period->fresh()->load('fiscalYear'), 'status' => $period->status]);
    }

    public function storeCurrency(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $data = $request->validate(['code' => ['required', 'string', 'size:3', Rule::unique('currencies', 'code')], 'name' => ['required', 'string', 'max:100'], 'symbol' => ['nullable', 'string', 'max:8'], 'decimal_places' => ['required', 'integer', 'min:0', 'max:8'], 'is_base' => ['nullable', 'boolean']]);
        $currency = DB::transaction(function () use ($data): Currency {
            if (!empty($data['is_base'])) Currency::where('is_base', true)->update(['is_base' => false]);
            $currency = Currency::create(['code' => strtoupper($data['code']), 'name' => $data['name'], 'symbol' => $data['symbol'] ?? null, 'decimal_places' => $data['decimal_places'], 'is_base' => (bool) ($data['is_base'] ?? false), 'is_active' => true]);
            app(AuditService::class)->record('currency.created', $currency, null, $currency->toArray());
            return $currency;
        });
        return response()->json(['data' => $currency, 'status' => 'created'], 201);
    }

    public function updateCurrency(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $currency = Currency::findOrFail($id);
        $data = $request->validate(['code' => ['required', 'string', 'size:3', Rule::unique('currencies', 'code')->ignore($currency->id)], 'name' => ['required', 'string', 'max:100'], 'symbol' => ['nullable', 'string', 'max:8'], 'decimal_places' => ['required', 'integer', 'min:0', 'max:8'], 'is_active' => ['nullable', 'boolean'], 'is_base' => ['nullable', 'boolean']]);
        $data['code'] = strtoupper($data['code']);
        $before = $currency->only(array_keys($data));
        DB::transaction(function () use ($currency, $data): void {
            if (!empty($data['is_base'])) Currency::where('id', '<>', $currency->id)->where('is_base', true)->update(['is_base' => false]);
            $currency->update($data);
        });
        app(AuditService::class)->record('currency.updated', $currency, $before, $currency->fresh()->only(array_keys($data)));
        return response()->json(['data' => $currency->fresh(), 'status' => 'updated']);
    }

    public function taxRates(Request $request): JsonResponse
    {
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'as_of' => ['nullable', 'date'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rates = $this->companyScope(TaxRate::query(), $request->user()?->company_id)
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['as_of'] ?? null, fn ($query, $date) => $query->where(fn ($scope) => $scope->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))->where(fn ($scope) => $scope->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date)))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rates, $request, 'accounting.tax-rates', (int) ($data['per_page'] ?? 50));
    }

    public function storeTaxRate(Request $request): JsonResponse
    {
        if (!$request->user()?->tokenCan('accounting:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify tax rates.');
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('tax_rates', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('jurisdiction', $request->input('jurisdiction'))->where('effective_from', $request->input('effective_from')))],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'calculation' => ['required', 'in:exclusive,inclusive'], 'jurisdiction' => ['nullable', 'string', 'max:100'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $this->assertTaxPeriodAvailable($data['code'], (int) $companyId, $data['jurisdiction'] ?? null, $data['effective_from'] ?? null, $data['effective_until'] ?? null);
        $tax = DB::transaction(function () use ($data, $companyId): TaxRate {
            $tax = TaxRate::create($data + ['company_id' => $companyId, 'is_active' => true]);
            app(AuditService::class)->record('tax_rate.created', $tax, null, $tax->toArray());
            return $tax;
        });
        return response()->json(['data' => $tax, 'status' => 'created'], 201);
    }

    public function updateTaxRate(Request $request, int $id): JsonResponse
    {
        if (!$request->user()?->tokenCan('accounting:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify tax rates.');
        $tax = $this->companyScope(TaxRate::query(), $request->user()?->company_id)->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('tax_rates', 'code')->ignore($tax->id)->where(fn ($query) => $query->where('company_id', $tax->company_id)->where('jurisdiction', $request->input('jurisdiction'))->where('effective_from', $request->input('effective_from')))],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'calculation' => ['required', 'in:exclusive,inclusive'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $this->assertTaxPeriodAvailable($data['code'], (int) $tax->company_id, $data['jurisdiction'] ?? null, $data['effective_from'] ?? null, $data['effective_until'] ?? null, $tax->id);
        $before = $tax->only(array_keys($data));
        $tax->update($data);
        app(AuditService::class)->record('tax_rate.updated', $tax, $before, $tax->fresh()->only(array_keys($data)));
        return response()->json(['data' => $tax->fresh(), 'status' => 'updated']);
    }

    public function currencies(Request $request): JsonResponse
    {
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $currencies = Currency::query()
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($currencies, $request, 'accounting.currencies', (int) ($data['per_page'] ?? 50));
    }

    public function exchangeRates(Request $request): JsonResponse
    {
        $data = $request->validate(['from_currency_id' => ['nullable', 'integer'], 'to_currency_id' => ['nullable', 'integer'], 'effective_date' => ['nullable', 'date'], 'source_type' => ['nullable', 'in:manual,provider,import'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rates = ExchangeRate::with(['fromCurrency', 'toCurrency'])
            ->when($data['from_currency_id'] ?? null, fn ($query, $id) => $query->where('from_currency_id', $id))
            ->when($data['to_currency_id'] ?? null, fn ($query, $id) => $query->where('to_currency_id', $id))
            ->when($data['effective_date'] ?? null, fn ($query, $date) => $query->whereDate('effective_date', $date))
            ->when($data['source_type'] ?? null, fn ($query, $source) => $query->where('source_type', $source))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rates, $request, 'accounting.exchange-rates', (int) ($data['per_page'] ?? 50));
    }

    public function storeExchangeRate(Request $request): JsonResponse
    {
        if (!$request->user()?->tokenCan('accounting:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify exchange rates.');
        $data = $request->validate([
            'from_currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'to_currency_id' => ['required', 'integer', 'different:from_currency_id', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'rate' => ['required', 'numeric', 'gt:0'], 'effective_date' => ['required', 'date'], 'source_type' => ['nullable', 'in:manual,provider,import'], 'source_reference' => ['nullable', 'string', 'max:150'], 'retrieved_at' => ['nullable', 'date'],
        ]);
        $data['source_type'] = $data['source_type'] ?? 'manual';
        if ($data['source_type'] !== 'manual' && empty($data['source_reference'])) abort(422, 'A provider or import exchange rate requires a source reference.');
        $data['retrieved_at'] = $data['retrieved_at'] ?? now();
        $rate = DB::transaction(function () use ($data): ExchangeRate {
            $rate = ExchangeRate::create($data + ['is_active' => true]);
            app(AuditService::class)->record('exchange_rate.created', $rate, null, $rate->toArray());
            return $rate;
        });
        return response()->json(['data' => $rate->load(['fromCurrency', 'toCurrency']), 'status' => 'created'], 201);
    }

    public function deactivateExchangeRate(int $id): JsonResponse
    {
        if (!request()->user()?->tokenCan('accounting:write') && !request()->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify exchange rates.');
        $rate = ExchangeRate::findOrFail($id);
        if (!$rate->is_active) return response()->json(['data' => $rate, 'status' => 'already_inactive']);
        $before = $rate->only(['is_active']);
        $rate->update(['is_active' => false]);
        app(AuditService::class)->record('exchange_rate.deactivated', $rate, $before, ['is_active' => false]);
        return response()->json(['data' => $rate->fresh()->load(['fromCurrency', 'toCurrency']), 'status' => 'deactivated']);
    }

    private function assertWriteAccess(Request $request): void
    {
        if (!$request->user()?->tokenCan('accounting:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify finance configuration.');
    }

    private function eliminationStatus(array $report): string
    {
        $manual = (int) ($report['elimination_journal_count'] ?? 0) > 0;
        $automatic = (int) ($report['automatic_elimination_journal_count'] ?? 0) > 0;
        return $manual && $automatic ? 'manual_and_automatic_applied' : ($manual ? 'manual_journals_applied' : ($automatic ? 'automatic_matches_applied' : 'not_applied'));
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    private function assertTaxPeriodAvailable(string $code, int $companyId, ?string $jurisdiction, ?string $from, ?string $until, ?int $ignore = null): void
    {
        $start = \Carbon\Carbon::parse($from ?: '1900-01-01');
        $end = \Carbon\Carbon::parse($until ?: '9999-12-31');
        $overlap = TaxRate::where('company_id', $companyId)->where('code', $code)->where('jurisdiction', $jurisdiction)
            ->when($ignore, fn ($query, $id) => $query->where('id', '<>', $id))
            ->get(['id', 'effective_from', 'effective_until'])
            ->first(function (TaxRate $rate) use ($start, $end): bool {
                $existingStart = $rate->effective_from ?: \Carbon\Carbon::parse('1900-01-01');
                $existingEnd = $rate->effective_until ?: \Carbon\Carbon::parse('9999-12-31');
                return $existingStart->lte($end) && $existingEnd->gte($start);
            });
        if ($overlap) abort(422, 'The tax-rate effective period overlaps an existing period for this code and jurisdiction.');
    }
}
