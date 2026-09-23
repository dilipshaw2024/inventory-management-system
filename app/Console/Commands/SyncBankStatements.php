<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Services\BankStatementImportService;
use App\Services\Integrations\BankStatementAdapterRegistry;
use App\Services\Integrations\BankStatementPoller;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncBankStatements extends Command
{
    protected $signature = 'erp:accounting:sync-bank-statements
        {--company= : Limit synchronization to a company ID}
        {--account= : Limit synchronization to a bank account ID}
        {--from= : Inclusive transaction date (defaults to the last sync date or the lookback window)}
        {--to= : Inclusive transaction date (defaults to today)}
        {--days=7 : Lookback window for accounts without a previous successful sync}';

    protected $description = 'Synchronize active provider-backed bank accounts and retain per-account health status';

    public function handle(BankStatementAdapterRegistry $adapters, BankStatementImportService $imports): int
    {
        $fromOption = $this->option('from');
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to'))->toDateString() : Carbon::today()->toDateString();
        $days = max(1, min(3650, (int) $this->option('days')));

        $accounts = BankAccount::query()
            ->where('is_active', true)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', (int) $company))
            ->when($this->option('account'), fn ($query, $account) => $query->whereKey((int) $account))
            ->orderBy('id');

        $synced = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($accounts->cursor() as $account) {
            $provider = strtolower(trim((string) ($account->provider ?: 'generic')));
            try {
                $adapter = $adapters->resolve($provider);
                if (!$adapter instanceof BankStatementPoller) {
                    $skipped++;
                    $this->line("Skipped bank account {$account->id}: provider '{$provider}' does not support polling.");
                    continue;
                }

                $from = $fromOption
                    ? Carbon::parse((string) $fromOption)->toDateString()
                    : ($account->last_synced_at?->copy()->toDateString() ?: Carbon::today()->subDays($days)->toDateString());
                if (Carbon::parse($from)->greaterThan(Carbon::parse($to))) {
                    throw new \RuntimeException('The synchronization start date is after the end date.');
                }

                $rows = $adapter->fetch((int) $account->id, [
                    'from' => $from,
                    'to' => $to,
                    'connection_config' => $account->connection_config,
                ]);
                $result = $imports->import($account->company_id, $provider, $rows, null, 'scheduled');
                $account->update(['last_synced_at' => now(), 'last_sync_status' => 'success', 'last_sync_error' => null]);
                $synced++;
                $this->info("Synced bank account {$account->id}: {$result['batch']->created_lines} new, {$result['batch']->duplicate_lines} duplicate line(s).");
            } catch (\Throwable $exception) {
                $account->update(['last_synced_at' => now(), 'last_sync_status' => 'failed', 'last_sync_error' => $exception->getMessage()]);
                $failed++;
                $this->error("Failed bank account {$account->id}: {$exception->getMessage()}");
            }
        }

        $this->line("Bank statement synchronization complete: {$synced} synced, {$failed} failed, {$skipped} skipped.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
