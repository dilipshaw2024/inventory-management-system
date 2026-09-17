<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\BankStatementImportBatch;
use App\Services\Integrations\BankStatementAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BankStatementImportService
{
    public function __construct(private BankStatementAdapterRegistry $adapters) {}

    public function import(?int $companyId, string $provider, array $rows, ?int $userId = null): array
    {
        $provider = strtolower(trim($provider ?: 'generic'));
        $adapter = $this->adapters->resolve($provider);

        return DB::transaction(function () use ($companyId, $provider, $adapter, $rows, $userId): array {
            $batch = BankStatementImportBatch::create([
                'company_id' => $companyId, 'provider' => $provider, 'source' => 'api', 'imported_by' => $userId,
                'total_lines' => count($rows), 'started_at' => now(), 'status' => 'completed',
            ]);
            $results = [];
            foreach ($rows as $row) {
                $direct = collect($row)->except(['provider', 'payload'])->filter(fn ($value): bool => $value !== null)->all();
                $payload = array_merge($row['payload'] ?? [], $direct);
                $normalized = $adapter->normalize($payload);
                $normalized['provider'] = $provider;
                $normalized['raw_payload'] = $row['payload'] ?? $payload;
                $data = Validator::make($normalized, [
                    'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
                    'provider' => ['required', 'string', 'max:50'], 'transaction_date' => ['required', 'date'],
                    'reference' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'],
                    'amount' => ['required', 'numeric', 'not_in:0'], 'external_reference' => ['nullable', 'string', 'max:150'], 'raw_payload' => ['nullable', 'array'],
                ])->validate();

                if (!empty($data['external_reference'])) {
                    $existing = $this->scope(BankStatementLine::query(), $companyId)->where('provider', $provider)->where('external_reference', $data['external_reference'])->first();
                    if ($existing) {
                        $results[] = ['data' => $existing, 'idempotent' => true];
                        continue;
                    }
                }

                $account = $this->scope(BankAccount::query(), $companyId)->findOrFail($data['bank_account_id']);
                $line = BankStatementLine::create($data + ['company_id' => $companyId ?: $account->company_id, 'import_batch_id' => $batch->id, 'status' => 'unmatched']);
                app(AuditService::class)->record('bank_statement_line.created', $line, null, $line->toArray() + ['bulk_import' => true, 'user_id' => $userId]);
                $results[] = ['data' => $line, 'idempotent' => false];
            }
            $batch->update([
                'created_lines' => collect($results)->where('idempotent', false)->count(),
                'duplicate_lines' => collect($results)->where('idempotent', true)->count(),
                'completed_at' => now(),
            ]);
            return ['batch' => $batch->fresh(), 'results' => $results];
        });
    }

    private function scope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
