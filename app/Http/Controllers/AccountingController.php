<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\AccountMapping;
use App\Models\CostCenter;
use App\Models\CostCenterBudget;
use App\Models\JournalLine;
use App\Models\InvoiceDetail;
use App\Models\PurchaseInvoiceLine;
use App\Models\InventoryReturn;
use App\Services\AccountingService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\ForeignCurrencyRevaluationService;
use App\Services\FinancialReportingService;
use App\Models\RecurringJournalTemplate;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    private function companyExists(string $table) { return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id')); }

    public function fxRevaluation(Request $request)
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $data['as_of'] ?? now()->toDateString();
        $rows = app(ForeignCurrencyRevaluationService::class)->openBalances($asOf, (int) auth()->user()?->company_id);
        return view('admin.erp.fx_revaluation', compact('rows', 'asOf'));
    }

    public function postFxRevaluation(Request $request)
    {
        $data = $request->validate(['as_of' => ['required', 'date']]);
        try {
            $journal = app(ForeignCurrencyRevaluationService::class)->post($data['as_of']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['revaluation' => $exception->getMessage()])->withInput();
        }
        app(AuditService::class)->record('fx_revaluation.posted', $journal, null, $journal->toArray());
        return back()->with(['message' => 'Foreign-currency revaluation journal '.$journal->entry_no.' posted.', 'alert-type' => 'success']);
    }

    public function taxReport(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax reporting.');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'jurisdiction' => ['nullable', 'string', 'max:100'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $jurisdiction = $data['jurisdiction'] ?? null;
        $taxKey = static fn (float $rate, ?string $jurisdiction): string => number_format($rate, 4, '.', '').'|'.($jurisdiction ?: 'UNSPECIFIED');

        $sales = InvoiceDetail::with('invoice')
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [1, 'approved'])->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->get()
            ->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction))
            ->map(fn ($lines): array => [
                'rate' => (float) $lines->first()->tax_rate,
                'taxable' => $lines->sum(fn ($line): float => (float) $line->selling_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0)),
                'tax' => $lines->sum(fn ($line): float => (float) $line->tax_amount),
                'documents' => $lines->pluck('invoice_id')->unique()->count(),
            ]);
        $salesReturns = InventoryReturn::with('lines.product')
            ->where('return_type', 'sales')->where('status', 'approved')->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($query) => $query->where('tax_jurisdiction', $jurisdiction))
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->get()->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line): array => ['return' => $return, 'line' => $line]))
            ->groupBy(fn (array $item): string => $taxKey((float) ($item['line']->tax_rate ?? 0), $item['return']->tax_jurisdiction))
            ->map(fn ($items): array => [
                'taxable' => $items->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0)),
                'tax' => $items->sum(fn (array $item): float => $item['return']->tax_exempt ? 0 : (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0) * (float) ($item['line']->tax_rate ?? 0) / 100),
                'documents' => $items->pluck('return.id')->unique()->count(),
            ]);
        $purchases = PurchaseInvoiceLine::with('invoice')
            ->whereHas('invoice', fn ($query) => $query->where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->get()
            ->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction))
            ->map(fn ($lines): array => [
                'rate' => (float) $lines->first()->tax_rate,
                'taxable' => $lines->sum(fn ($line): float => (float) $line->quantity * (float) $line->unit_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0)),
                'tax' => $lines->sum(fn ($line): float => (float) $line->tax_amount),
                'documents' => $lines->pluck('purchase_invoice_id')->unique()->count(),
            ]);
        $rates = $sales->keys()->merge($salesReturns->keys())->merge($purchases->keys())->unique()->sort()->values();
        $rows = $rates->map(function ($rate) use ($sales, $salesReturns, $purchases): array {
            $sale = $sales->get($rate, ['taxable' => 0, 'tax' => 0, 'documents' => 0]);
            $return = $salesReturns->get($rate, ['taxable' => 0, 'tax' => 0, 'documents' => 0]);
            [$rateValue, $jurisdiction] = explode('|', $rate, 2);
            return [
            'rate' => (float) $rateValue, 'jurisdiction' => $jurisdiction === 'UNSPECIFIED' ? null : $jurisdiction,
            'sales' => ['taxable' => (float) $sale['taxable'] - (float) $return['taxable'], 'tax' => (float) $sale['tax'] - (float) $return['tax'], 'documents' => $sale['documents'], 'return_documents' => $return['documents']],
            'purchases' => $purchases->get($rate, ['taxable' => 0, 'tax' => 0, 'documents' => 0]),
            ];
        });
        return view('admin.erp.tax_report', compact('rows', 'from', 'to', 'jurisdiction'));
    }

    public function taxReportExport(Request $request): StreamedResponse
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'jurisdiction' => ['nullable', 'string', 'max:100']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $jurisdiction = $data['jurisdiction'] ?? null;
        $taxKey = static fn (float $rate, ?string $jurisdiction): string => number_format($rate, 4, '.', '').'|'.($jurisdiction ?: 'UNSPECIFIED');
        $sales = InvoiceDetail::with('invoice')->whereHas('invoice', fn ($query) => $query->whereIn('status', [1, 'approved'])->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction));
        $salesReturns = InventoryReturn::with('lines.product')->where('return_type', 'sales')->where('status', 'approved')->whereBetween('date', [$from, $to])->when($jurisdiction !== null, fn ($query) => $query->where('tax_jurisdiction', $jurisdiction))->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line): array => ['return' => $return, 'line' => $line]))->groupBy(fn (array $item): string => $taxKey((float) ($item['line']->tax_rate ?? 0), $item['return']->tax_jurisdiction));
        $purchases = PurchaseInvoiceLine::with('invoice')->whereHas('invoice', fn ($query) => $query->where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->when($jurisdiction !== null, fn ($scope) => $scope->where('tax_jurisdiction', $jurisdiction))->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->groupBy(fn ($line): string => $taxKey((float) ($line->tax_rate ?? 0), $line->invoice?->tax_jurisdiction));
        $rates = $sales->keys()->merge($salesReturns->keys())->merge($purchases->keys())->unique()->sort()->values();
        return response()->streamDownload(function () use ($rates, $sales, $salesReturns, $purchases): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Tax rate', 'Jurisdiction', 'Sales taxable', 'Output tax', 'Purchase taxable', 'Input tax', 'Net payable/(credit)', 'Documents']);
            foreach ($rates as $rate) {
                [$rateValue, $jurisdiction] = explode('|', $rate, 2);
                $sale = $sales->get($rate, collect()); $return = $salesReturns->get($rate, collect()); $purchase = $purchases->get($rate, collect());
                $salesTax = (float) $sale->sum('tax_amount') - (float) $return->sum(fn (array $item): float => $item['return']->tax_exempt ? 0 : (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0) * (float) ($item['line']->tax_rate ?? 0) / 100); $purchaseTax = (float) $purchase->sum('tax_amount');
                $salesBase = (float) $sale->sum(fn ($line): float => (float) $line->selling_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0)) - (float) $return->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0));
                $purchaseBase = (float) $purchase->sum(fn ($line): float => (float) $line->quantity * (float) $line->unit_price - ($line->invoice?->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0));
                fputcsv($output, [(float) $rateValue, $jurisdiction === 'UNSPECIFIED' ? '' : $jurisdiction, number_format($salesBase, 2, '.', ''), number_format($salesTax, 2, '.', ''), number_format($purchaseBase, 2, '.', ''), number_format($purchaseTax, 2, '.', ''), number_format($salesTax - $purchaseTax, 2, '.', ''), $sale->pluck('invoice_id')->merge($purchase->pluck('purchase_invoice_id'))->unique()->count()]);
            }
            fclose($output);
        }, 'tax-report-'.$from.'-to-'.$to.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function accounts()
    {
        $accounts = ChartOfAccount::with('parent')->orderBy('code')->paginate(50);
        return view('admin.erp.accounts', compact('accounts'));
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'parent_id' => ['nullable', 'integer', $this->companyExists('chart_of_accounts')],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:asset,liability,equity,income,expense'],
            'is_control_account' => ['nullable', 'boolean'],
        ]);
        $companyId = auth()->user()?->company_id ?: ($data['company_id'] ?? null);
        if (auth()->user()?->company_id && isset($data['company_id']) && (int) $data['company_id'] !== (int) $companyId) abort(403);
        if (ChartOfAccount::where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            return back()->withErrors(['code' => 'This account code already exists.'])->withInput();
        }
        $account = ChartOfAccount::create($data + ['company_id' => $companyId, 'is_control_account' => (bool) ($data['is_control_account'] ?? false)]);
        app(AuditService::class)->record('chart_of_account.created', $account, null, $account->toArray());
        return back()->with(['message' => 'Account created.', 'alert-type' => 'success']);
    }

    public function updateAccount(Request $request, int $id)
    {
        $account = ChartOfAccount::findOrFail($id);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', $this->companyExists('chart_of_accounts')],
            'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:asset,liability,equity,income,expense'],
            'is_control_account' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
        ]);
        if (!empty($data['parent_id']) && (int) $data['parent_id'] === $account->id) return back()->withErrors(['parent_id' => 'An account cannot be its own parent.'])->withInput();
        if (!empty($data['parent_id'])) {
            $parent = ChartOfAccount::findOrFail((int) $data['parent_id']);
            if (!$parent->is_active) return back()->withErrors(['parent_id' => 'An inactive account cannot be selected as a parent.'])->withInput();
            $ancestorId = $parent->parent_id; $visited = [];
            while ($ancestorId !== null && !in_array((int) $ancestorId, $visited, true)) {
                if ((int) $ancestorId === $account->id) return back()->withErrors(['parent_id' => 'The selected parent would create an account hierarchy cycle.'])->withInput();
                $visited[] = (int) $ancestorId;
                $ancestorId = ChartOfAccount::whereKey($ancestorId)->value('parent_id');
            }
        }
        if (ChartOfAccount::where('company_id', $account->company_id)->where('code', $data['code'])->where('id', '<>', $account->id)->exists()) return back()->withErrors(['code' => 'This account code already exists.'])->withInput();
        if ($account->lines()->exists() && $data['account_type'] !== $account->account_type) return back()->withErrors(['account_type' => 'The account type cannot change after journal lines exist.'])->withInput();
        $before = $account->toArray();
        $account->update($data + ['is_control_account' => (bool) ($data['is_control_account'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('chart_of_account.updated', $account, $before, $account->fresh()->toArray());
        return back()->with(['message' => 'Account updated.', 'alert-type' => 'success']);
    }

    public function deactivateAccount(int $id)
    {
        $account = ChartOfAccount::findOrFail($id);
        if (AccountMapping::where('account_id', $account->id)->exists()) return back()->withErrors(['account' => 'Mapped control accounts cannot be deactivated until mappings are changed.']);
        if ($account->is_active) {
            $account->update(['is_active' => false]);
            app(AuditService::class)->record('chart_of_account.deactivated', $account, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Account deactivated.', 'alert-type' => 'success']);
    }

    public function journals()
    {
        $journals = JournalEntry::with(['lines.account', 'creator', 'reversal'])->latest('date')->latest('id')->paginate(50);
        $accounts = ChartOfAccount::where('is_active', true)->orderBy('code')->get();
        $costCenters = CostCenter::where('is_active', true)->orderBy('code')->get();
        return view('admin.erp.journals', compact('journals', 'accounts', 'costCenters'));
    }

    public function trialBalance(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for trial balance reporting.');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer', $this->companyExists('chart_of_accounts')],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $accounts = ChartOfAccount::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['account_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('code')->get();
        $lines = JournalLine::with('entry')->whereHas('entry', fn ($query) => $query->where('status', 'posted')->whereDate('date', '<=', $to)->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->get()->groupBy('account_id');
        $rows = $accounts->map(function (ChartOfAccount $account) use ($lines, $from, $to): array {
            $accountLines = $lines->get($account->id, collect());
            $opening = $accountLines->filter(fn ($line): bool => $line->entry->date->toDateString() < $from);
            $period = $accountLines->filter(fn ($line): bool => $line->entry->date->toDateString() >= $from && $line->entry->date->toDateString() <= $to);
            $openingDebit = (float) $opening->sum('debit'); $openingCredit = (float) $opening->sum('credit'); $periodDebit = (float) $period->sum('debit'); $periodCredit = (float) $period->sum('credit');
            return ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type, 'opening_debit' => $openingDebit, 'opening_credit' => $openingCredit, 'period_debit' => $periodDebit, 'period_credit' => $periodCredit, 'closing_debit' => $openingDebit + $periodDebit, 'closing_credit' => $openingCredit + $periodCredit, 'net_balance' => ($openingDebit + $periodDebit) - ($openingCredit + $periodCredit)];
        })->filter(fn (array $row): bool => abs($row['closing_debit']) > 0.000001 || abs($row['closing_credit']) > 0.000001)->values();
        return view('admin.erp.trial_balance', compact('rows', 'from', 'to', 'accounts'));
    }

    public function financialStatements(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for financial statements.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'account_id' => ['nullable', 'integer', $this->companyExists('chart_of_accounts')]]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $report = app(FinancialReportingService::class)->statements((int) $companyId, $from, $to, isset($data['account_id']) ? (int) $data['account_id'] : null);
        return view('admin.erp.financial_statements', ['from' => $from, 'to' => $to, 'accounts' => $report['accounts'], 'profitAndLoss' => $report['profit_and_loss'], 'balanceSheet' => $report['balance_sheet'], 'summary' => $report['summary']]);
    }

    public function cashFlow(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for cash-flow reporting.');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $report = app(FinancialReportingService::class)->cashFlow((int) $companyId, $from, $to);
        return view('admin.erp.cash_flow', ['from' => $from, 'to' => $to, 'rows' => $report['rows'], 'summary' => $report['summary'], 'cashAccounts' => $report['cash_accounts']]);
    }

    public function recurringJournals()
    {
        $templates = RecurringJournalTemplate::with('creator')->latest()->paginate(50);
        $accounts = ChartOfAccount::where('is_active', true)->orderBy('code')->get();
        return view('admin.erp.recurring_journals', compact('templates', 'accounts'));
    }

    public function storeRecurringJournal(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'starts_on' => ['required', 'date'],
            'next_run_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', $this->companyExists('chart_of_accounts')],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cost_center_id' => ['nullable', 'integer', $this->companyExists('cost_centers')],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
        ]);
        $companyId = auth()->user()?->company_id;
        if (!$companyId) abort(422, 'A company is required for recurring journals.');
        if (RecurringJournalTemplate::where('company_id', $companyId)->where('name', $data['name'])->exists()) {
            return back()->withErrors(['name' => 'A recurring journal with this name already exists.'])->withInput();
        }
        $debit = round((float) collect($data['lines'])->sum('debit'), 6);
        $credit = round((float) collect($data['lines'])->sum('credit'), 6);
        if ($debit <= 0 || abs($debit - $credit) > 0.000001) {
            return back()->withErrors(['lines' => 'Recurring journal debits and credits must balance above zero.'])->withInput();
        }
        $template = RecurringJournalTemplate::create([
            'company_id' => $companyId,
            'created_by' => auth()->id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'frequency' => $data['frequency'],
            'interval' => $data['interval'],
            'starts_on' => $data['starts_on'],
            'next_run_on' => $data['next_run_on'] ?? $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'lines' => array_values($data['lines']),
        ]);
        app(AuditService::class)->record('recurring_journal.created', $template, null, $template->toArray());
        return back()->with(['message' => 'Recurring journal template created.', 'alert-type' => 'success']);
    }

    public function deactivateRecurringJournal(int $id)
    {
        $template = RecurringJournalTemplate::findOrFail($id);
        if ($template->is_active) {
            $template->update(['is_active' => false]);
            app(AuditService::class)->record('recurring_journal.deactivated', $template, ['is_active' => true], ['is_active' => false]);
        }
        return back()->with(['message' => 'Recurring journal template deactivated.', 'alert-type' => 'success']);
    }

    public function storeJournal(Request $request)
    {
        $data = $request->validate([
            'entry_no' => ['nullable', 'string', 'max:80', 'unique:journal_entries,entry_no'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', $this->companyExists('chart_of_accounts')],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cost_center_id' => ['nullable', 'integer', $this->companyExists('cost_centers')],
        ]);
        try {
            $header = ['company_id' => auth()->user()?->company_id, 'entry_no' => $data['entry_no'] ?: 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => $data['date'], 'description' => $data['description'] ?? 'Manual journal'];
            $journal = $request->input('action') === 'draft' ? app(AccountingService::class)->createDraft($header, $data['lines']) : app(AccountingService::class)->post($header, $data['lines']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['journal' => $exception->getMessage()])->withInput();
        }
        app(AuditService::class)->record('journal.created', $journal, null, $journal->toArray());
        return back()->with(['message' => $journal->status === 'draft' ? 'Journal draft saved.' : 'Journal posted.', 'alert-type' => 'success']);
    }

    public function approveJournal(int $id)
    {
        $journal = JournalEntry::findOrFail($id);
        try { app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(JournalEntry::class, $id); app(\App\Services\ApprovalGuard::class)->assertDifferent($journal); $approved = app(AccountingService::class)->approve($journal); }
        catch (\RuntimeException $exception) { return back()->withErrors(['journal' => $exception->getMessage()]); }
        app(AuditService::class)->record('journal.approved', $approved, ['status' => 'draft'], ['status' => 'posted']);
        return back()->with(['message' => 'Journal approved and posted.', 'alert-type' => 'success']);
    }

    public function reverseJournal(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $journal = JournalEntry::findOrFail($id);
        try {
            $reversal = app(AccountingService::class)->reverse($journal, $data['reason'] ?? null);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['journal' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('journal.reversed', $journal, ['status' => 'posted'], ['status' => 'reversed', 'reversal_id' => $reversal->id]);
        return back()->with(['message' => 'Journal reversed.', 'alert-type' => 'success']);
    }

    public function mappings()
    {
        $mappings = AccountMapping::with('account')->orderBy('mapping_key')->get();
        $accounts = ChartOfAccount::where('is_active', true)->orderBy('code')->get();
        return view('admin.erp.mappings', compact('mappings', 'accounts'));
    }

    public function costCenterReport(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id']]);
        $lines = JournalLine::with(['costCenter', 'account', 'entry'])->whereHas('entry', fn ($query) => $query->where('status', 'posted'))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('date', '>=', $date)))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('date', '<=', $date)))
            ->when(array_key_exists('cost_center_id', $data) && $data['cost_center_id'] !== null, fn ($query) => $query->where('cost_center_id', $data['cost_center_id']))->get();
        $budgets = CostCenterBudget::with('costCenter')
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('period_end', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('period_start', '<=', $date))
            ->when(array_key_exists('cost_center_id', $data) && $data['cost_center_id'] !== null, fn ($query) => $query->where('cost_center_id', $data['cost_center_id']))
            ->get()->groupBy('cost_center_id');
        $lineGroups = $lines->groupBy(fn ($line) => $line->cost_center_id ?: 0);
        $rows = $lineGroups->keys()->merge($budgets->keys())->unique()->sort()->map(function ($centerId) use ($lineGroups, $budgets): array {
            $group = $lineGroups->get($centerId, collect());
            $budgetRows = $budgets->get($centerId, collect());
            $debit = (float) $group->sum('debit');
            return ['cost_center' => $group->first()?->costCenter ?: $budgetRows->first()?->costCenter, 'debit' => $debit, 'credit' => (float) $group->sum('credit'), 'net' => (float) $group->sum(fn ($line) => (float) $line->debit - (float) $line->credit), 'budget' => (float) $budgetRows->sum('budget_amount'), 'variance' => (float) $budgetRows->sum('budget_amount') - $debit, 'lines' => $group];
        })->values();
        $costCenters = CostCenter::where('is_active', true)->orderBy('code')->get();
        return view('admin.erp.cost_center_report', compact('rows', 'costCenters'));
    }

    public function storeMapping(Request $request)
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'mapping_key' => ['required', 'in:inventory,grni,cogs,inventory_loss,supplier_claim_recovery,landed_cost_clearing,accounts_payable,accounts_receivable,sales_revenue,sales_tax,cash_bank,cash,bank,fx_gain,fx_loss,payroll_expense,payroll_payable,payroll_deductions'], 'account_id' => ['required', 'integer', $this->companyExists('chart_of_accounts')]]);
        $userCompanyId = auth()->user()?->company_id;
        if ($userCompanyId && isset($data['company_id']) && (int) $data['company_id'] !== (int) $userCompanyId) abort(403, 'A mapping can only be changed for the current company.');
        $companyId = $userCompanyId ?: ($data['company_id'] ?? null);
        $mapping = AccountMapping::updateOrCreate(['company_id' => $companyId, 'mapping_key' => $data['mapping_key']], ['account_id' => $data['account_id']]);
        app(AuditService::class)->record('account_mapping.updated', $mapping, null, $mapping->toArray());
        return back()->with(['message' => 'Account mapping saved.', 'alert-type' => 'success']);
    }
}
