<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Services\AuditService;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function index()
    {
        $accounts = BankAccount::with('glAccount')->where('is_active', true)->orderBy('name')->get();
        $lines = BankStatementLine::with('bankAccount')->latest('transaction_date')->latest('id')->paginate(50);
        $payments = Payment::where('is_reversed', false)->where('paid_amount', '>', 0)->latest()->limit(100)->get();
        $supplierPayments = SupplierPayment::where('status', 'approved')->where('is_reversed', false)->latest()->limit(100)->get();
        $glAccounts = ChartOfAccount::where('is_active', true)->orderBy('code')->get();
        return view('backend.accounting.bank_reconciliation', compact('accounts', 'lines', 'payments', 'supplierPayments', 'glAccounts'));
    }

    public function storeAccount(Request $request)
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'));
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'account_no' => ['nullable', 'string', 'max:80'], 'currency_code' => ['required', 'string', 'size:3'], 'gl_account_id' => ['nullable', 'integer', $owned('chart_of_accounts')] ]);
        $account = BankAccount::create($data + ['company_id' => auth()->user()?->company_id, 'currency_code' => strtoupper($data['currency_code']), 'is_active' => true]);
        app(AuditService::class)->record('bank_account.created', $account, null, $account->toArray());
        return back()->with(['message' => 'Bank account created.', 'alert-type' => 'success']);
    }

    public function storeLine(Request $request)
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'transaction_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'amount' => ['required', 'numeric', 'not_in:0'], 'external_reference' => ['nullable', 'string', 'max:150']]);
        $line = BankStatementLine::create($data + ['company_id' => auth()->user()?->company_id, 'status' => 'unmatched']);
        app(AuditService::class)->record('bank_statement_line.created', $line, null, $line->toArray());
        return back()->with(['message' => 'Bank statement line imported.', 'alert-type' => 'success']);
    }

    public function importCsv(Request $request)
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'file' => ['required', 'file', 'max:10240']]);
        $file = fopen($request->file('file')->getRealPath(), 'rb');
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), fgetcsv($file) ?: []);
        foreach (['transaction_date', 'amount'] as $required) if (!in_array($required, $headers, true)) { fclose($file); return back()->withErrors(['file' => 'CSV must contain transaction_date and amount columns.']); }
        $created = 0; $skipped = 0; $errors = []; $seen = [];
        DB::transaction(function () use ($file, $headers, $data, &$created, &$skipped, &$errors, &$seen): void {
            while (($row = fgetcsv($file)) !== false) {
                if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) continue;
                $record = array_combine($headers, array_slice(array_pad($row, count($headers), null), 0, count($headers)));
                $validation = Validator::make($record, ['transaction_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'not_in:0'], 'reference' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'external_reference' => ['nullable', 'string', 'max:150']]);
                if ($validation->fails()) { $errors[] = 'Row '.($created + $skipped + count($errors) + 2).': '.$validation->errors()->first(); continue; }
                $external = trim((string) ($record['external_reference'] ?? ''));
                if ($external && (isset($seen[$external]) || BankStatementLine::where('external_reference', $external)->exists())) { $skipped++; continue; }
                BankStatementLine::create(['company_id' => auth()->user()?->company_id, 'bank_account_id' => $data['bank_account_id'], 'transaction_date' => $record['transaction_date'], 'reference' => $record['reference'] ?? null, 'description' => $record['description'] ?? null, 'amount' => $record['amount'], 'external_reference' => $external ?: null, 'status' => 'unmatched']);
                if ($external) $seen[$external] = true;
                $created++;
            }
        });
        fclose($file);
        return back()->with(['message' => "Imported {$created} bank lines; skipped {$skipped} duplicates.".($errors ? ' Errors: '.implode(' | ', array_slice($errors, 0, 3)) : ''), 'alert-type' => $errors ? 'warning' : 'success']);
    }

    public function match(Request $request, int $id)
    {
        $data = $request->validate(['target_type' => ['required', 'in:customer_payment,supplier_payment'], 'target_id' => ['required', 'integer', 'min:1']]);
        try { $target = app(BankReconciliationService::class)->match(BankStatementLine::findOrFail($id), $data['target_type'], (int) $data['target_id']); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { return back()->withErrors(['match' => $exception->getMessage()]); }
        $line = BankStatementLine::findOrFail($id);
        app(AuditService::class)->record('bank_statement_line.matched', $line, ['status' => 'unmatched'], ['status' => 'matched', 'target_type' => $data['target_type'], 'target_id' => $target->id]);
        return back()->with(['message' => 'Bank statement line matched.', 'alert-type' => 'success']);
    }

    public function suggestions(int $id)
    {
        $line = BankStatementLine::findOrFail($id);
        return response()->json(['statement_line_id' => $line->id, 'suggestions' => app(BankReconciliationService::class)->suggestions($line)]);
    }

    public function setStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'in:unmatched,ignored']]);
        $line = BankStatementLine::findOrFail($id);
        $old = $line->status;
        try {
            $line = app(BankReconciliationService::class)->setStatus($line, $data['status']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('bank_statement_line.status_changed', $line, ['status' => $old], ['status' => $line->status]);
        return back()->with(['message' => 'Bank statement line status updated.', 'alert-type' => 'success']);
    }

    public function reverseMatch(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $line = app(BankReconciliationService::class)->reverseMatch(BankStatementLine::findOrFail($id), $data['reason']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }
        app(AuditService::class)->record('bank_statement_line.match_reversed', $line, ['status' => 'matched'], ['status' => 'unmatched', 'reason' => $data['reason']]);
        return back()->with(['message' => 'Bank statement match reversed.', 'alert-type' => 'success']);
    }
}
