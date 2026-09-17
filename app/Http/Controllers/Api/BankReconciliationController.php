<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\BankStatementImportBatch;
use App\Services\AuditService;
use App\Services\BankReconciliationService;
use App\Services\BankStatementImportService;
use App\Services\Integrations\BankStatementAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function __construct(private BankStatementAdapterRegistry $bankAdapters) {}

    public function lines(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $accountScope = Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['bank_account_id' => ['nullable', 'integer', $accountScope], 'provider' => ['nullable', 'string', 'max:50'], 'status' => ['nullable', 'in:unmatched,matched,ignored'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $lines = $this->companyScope(BankStatementLine::with(['bankAccount', 'importBatch']), $companyId)->when($data['bank_account_id'] ?? null, fn ($query, $id) => $query->where('bank_account_id', $id))->when($data['provider'] ?? null, fn ($query, $provider) => $query->where('provider', strtolower(trim($provider))))->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '<=', $date))->latest('transaction_date')->paginate(100);
        return response()->json($lines);
    }

    public function batches(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:50'], 'status' => ['nullable', 'in:completed,failed'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $batches = $this->companyScope(BankStatementImportBatch::with('importer'), $companyId)
            ->when($data['provider'] ?? null, fn ($query, $provider) => $query->where('provider', strtolower(trim($provider))))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')->latest('id')->paginate((int) ($data['per_page'] ?? 50));
        return response()->json($batches);
    }

    public function storeLine(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $accountScope = Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['bank_account_id' => ['nullable', 'integer', $accountScope], 'provider' => ['nullable', 'string', 'max:50'], 'payload' => ['nullable', 'array'], 'transaction_date' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'amount' => ['nullable', 'numeric', 'not_in:0'], 'external_reference' => ['nullable', 'string', 'max:150']]);
        $provider = strtolower(trim($data['provider'] ?? 'generic'));
        $directPayload = collect($data)->except(['provider', 'payload'])->filter(fn ($value): bool => $value !== null)->all();
        $adapterPayload = array_merge($data['payload'] ?? [], $directPayload);
        try { $normalized = $this->bankAdapters->resolve($provider)->normalize($adapterPayload); }
        catch (\InvalidArgumentException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $normalized['provider'] = $provider;
        $normalized['raw_payload'] = $data['payload'] ?? $adapterPayload;
        $data = validator($normalized, ['bank_account_id' => ['required', 'integer', $accountScope], 'provider' => ['required', 'string', 'max:50'], 'transaction_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'amount' => ['required', 'numeric', 'not_in:0'], 'external_reference' => ['nullable', 'string', 'max:150'], 'raw_payload' => ['nullable', 'array']])->validate();
        if (!empty($data['external_reference']) && ($existing = $this->companyScope(BankStatementLine::query(), $companyId)->where('provider', $provider)->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing, 'idempotent' => true]);
        $account = $this->companyScope(BankAccount::query(), $companyId)->findOrFail($data['bank_account_id']);
        $line = BankStatementLine::create($data + ['company_id' => $companyId ?: $account->company_id, 'status' => 'unmatched']);
        app(AuditService::class)->record('bank_statement_line.created', $line, null, $line->toArray());
        return response()->json(['data' => $line, 'idempotent' => false], 201);
    }

    public function storeLines(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:50'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*' => ['required', 'array'],
        ]);
        try {
            $import = app(BankStatementImportService::class)->import(
                $request->user()?->company_id,
                $data['provider'] ?? 'generic',
                $data['lines'],
                $request->user()?->id
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json([
            'batch_id' => $import['batch']->id,
            'data' => collect($import['results'])->map(fn (array $result) => $result['data'])->values(),
            'created' => collect($import['results'])->where('idempotent', false)->count(),
            'duplicates' => collect($import['results'])->where('idempotent', true)->count(),
        ], 201);
    }

    public function match(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['target_type' => ['required', 'in:customer_payment,supplier_payment'], 'target_id' => ['required', 'integer', 'min:1']]);
        try { $target = app(BankReconciliationService::class)->match($this->line($id), $data['target_type'], (int) $data['target_id']); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $line = $this->line($id);
        app(AuditService::class)->record('bank_statement_line.matched', $line, ['status' => 'unmatched'], ['status' => 'matched', 'target_type' => $data['target_type'], 'target_id' => $target->id]);
        return response()->json(['data' => $line, 'target' => $target]);
    }

    public function suggestions(int $id): JsonResponse
    {
        $line = $this->line($id);
        return response()->json(['data' => app(BankReconciliationService::class)->suggestions($line), 'statement_line_id' => $line->id]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:unmatched,ignored']]);
        $line = $this->line($id);
        $old = $line->status;
        try {
            $line = app(BankReconciliationService::class)->setStatus($line, $data['status']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        app(AuditService::class)->record('bank_statement_line.status_changed', $line, ['status' => $old], ['status' => $line->status]);
        return response()->json(['data' => $line]);
    }

    public function reverseMatch(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $line = $this->line($id);
        try {
            $line = app(BankReconciliationService::class)->reverseMatch($line, $data['reason']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        app(AuditService::class)->record('bank_statement_line.match_reversed', $line, ['status' => 'matched'], ['status' => 'unmatched', 'reason' => $data['reason']]);
        return response()->json(['data' => $line, 'status' => 'unmatched']);
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $accountScope = Rule::exists('chart_of_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate(['account_id' => ['required', 'integer', $accountScope], 'reason' => ['required', 'string', 'max:2000']]);
        $line = $this->line($id);
        try { $line = app(BankReconciliationService::class)->settle($line, (int) $data['account_id'], $data['reason']); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('bank_statement_line.settled', $line, ['status' => 'unmatched'], $line->toArray());
        return response()->json(['data' => $line, 'status' => 'settled']);
    }

    public function reverseSettlement(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try { $line = app(BankReconciliationService::class)->reverseSettlement($this->line($id), $data['reason']); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('bank_statement_line.settlement_reversed', $line, ['status' => 'matched'], $line->toArray());
        return response()->json(['data' => $line, 'status' => 'unmatched']);
    }

    private function line(int $id): BankStatementLine
    {
        return $this->companyScope(BankStatementLine::query(), auth()->user()?->company_id)->findOrFail($id);
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
