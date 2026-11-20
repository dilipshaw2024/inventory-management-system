<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Scheduling\Schedule;

class ErpHealthService
{
    public function check(): array
    {
        $checks = [];
        $databaseAvailable = false;
        $requiredTables = [
            'users', 'companies', 'products', 'inventory_movements', 'inventory_cost_layers',
            'purchase_orders', 'sales_orders', 'journal_entries', 'fiscal_years', 'fiscal_periods',
            'audit_logs', 'integration_webhook_subscriptions', 'integration_webhook_deliveries',
        ];

        $checks['application_key'] = filled(config('app.key'))
            ? ['ok', 'configured']
            : ['fail', 'APP_KEY is not configured'];

        try {
            DB::connection()->getPdo();
            $databaseAvailable = true;
            $checks['database'] = ['ok', 'reachable'];
            if (!Schema::hasTable('migrations')) {
                $checks['migrations'] = ['fail', 'migration table is missing'];
            } else {
                $migrator = app('migrator');
                $ran = $migrator->getRepository()->getRan();
                $available = array_keys($migrator->getMigrationFiles(database_path('migrations')));
                $pending = array_values(array_diff($available, $ran));
                $checks['migrations'] = empty($pending)
                    ? ['ok', count($ran).' migrations applied']
                    : ['fail', count($pending).' pending migration(s): '.implode(', ', array_slice($pending, 0, 10))];
            }
            $missingTables = collect($requiredTables)
                ->reject(fn (string $table): bool => Schema::hasTable($table))
                ->values()->all();
            $checks['erp_schema'] = empty($missingTables)
                ? ['ok', count($requiredTables).' core ERP tables available']
                : ['fail', 'missing tables: '.implode(', ', $missingTables)];
        } catch (\Throwable $exception) {
            $connectionName = (string) config('database.default', 'unknown');
            $connection = (array) config('database.connections.'.$connectionName, []);
            $target = $connectionName === 'sqlite'
                ? (string) ($connection['database'] ?? 'sqlite')
                : trim((string) ($connection['host'] ?? 'unknown')).':'.((string) ($connection['port'] ?? 'default'));
            $checks['database'] = ['fail', 'connection '.$connectionName.' ('.$target.') failed: '.$exception->getMessage()];
            $checks['migrations'] = ['fail', 'not checked because the database is unavailable'];
            $checks['erp_schema'] = ['fail', 'not checked because the database is unavailable'];
        }

        $queueName = (string) config('queue.default');
        $queueConfig = config('queue.connections.'.$queueName);
        if (!filled($queueName) || !is_array($queueConfig) || !filled($queueConfig['driver'] ?? null)) {
            $checks['queue'] = ['fail', 'default queue connection is not configured'];
        } elseif (($queueConfig['driver'] ?? null) === 'database' && !$databaseAvailable) {
            $checks['queue'] = ['fail', 'database queue cannot be verified because the database is unavailable'];
        } elseif (($queueConfig['driver'] ?? null) === 'database' && !Schema::hasTable('jobs')) {
            $checks['queue'] = ['fail', 'database queue is configured but the jobs table is missing'];
        } else {
            $checks['queue'] = ['ok', 'configured: '.$queueName];
        }

        $requiredScheduledCommands = [
            'erp:inventory:expiry-alerts', 'erp:inventory:low-stock-alerts', 'erp:inventory:exception-alerts',
            'erp:planning:generate-purchase-orders', 'erp:accounting:generate-recurring-journals',
            'erp:approvals:escalate', 'erp:integration:deliver-webhooks', 'erp:security:verify-audit-chain',
            'erp:reconcile-inventory-ledger', 'erp:retention:verify-archives',
            'erp:accounting:retry-tax-filings', 'erp:accounting:retry-e-invoices',
            'erp:logistics:sync-carrier-tracking', 'erp:products:process-import-jobs',
        ];
        try {
            $scheduledCommands = collect(app(Schedule::class)->events())
                ->map(fn ($event): string => (string) ($event->command ?? ''));
            $missingScheduledCommands = collect($requiredScheduledCommands)
                ->reject(fn (string $command): bool => $scheduledCommands->contains(fn (string $scheduled): bool => str_contains($scheduled, $command)))
                ->values()->all();
            $checks['scheduler'] = empty($missingScheduledCommands)
                ? ['ok', count($requiredScheduledCommands).' critical ERP schedules registered']
                : ['fail', 'missing scheduled commands: '.implode(', ', $missingScheduledCommands)];
        } catch (\Throwable $exception) {
            $checks['scheduler'] = ['fail', 'scheduler registration could not be inspected: '.$exception->getMessage()];
        }

        $cacheName = (string) config('cache.default');
        $cacheConfig = config('cache.stores.'.$cacheName);
        $checks['cache'] = filled($cacheName) && is_array($cacheConfig)
            ? ['ok', 'configured: '.$cacheName]
            : ['fail', 'default cache store is not configured'];

        $checks['production_debug'] = app()->environment('production') && config('app.debug')
            ? ['fail', 'APP_DEBUG must be false in production']
            : ['ok', app()->environment('production') ? 'disabled' : 'non-production environment'];

        try {
            Storage::disk('local')->put('.erp-health-check', now()->toIso8601String());
            Storage::disk('local')->delete('.erp-health-check');
            $checks['private_storage'] = ['ok', 'writable'];
        } catch (\Throwable $exception) {
            $checks['private_storage'] = ['fail', $exception->getMessage()];
        }

        $archiveDisk = trim((string) config('retention.archive_disk', ''));
        if ($archiveDisk === '') {
            $checks['retention_archive_storage'] = ['ok', 'not configured; database-only retention archives are active'];
        } else {
            try {
                Storage::disk($archiveDisk);
                $checks['retention_archive_storage'] = ['ok', 'configured: '.$archiveDisk.' (run erp:retention:verify-archives for object integrity)'];
            } catch (\Throwable $exception) {
                $checks['retention_archive_storage'] = ['fail', 'configured disk '.$archiveDisk.' is unavailable: '.$exception->getMessage()];
            }
        }

        $failed = collect($checks)->filter(fn (array $check): bool => $check[0] !== 'ok')->count();

        return [
            'status' => $failed === 0 ? 'healthy' : 'unhealthy',
            'checks' => collect($checks)->map(fn (array $check, string $name): array => [
                'name' => $name, 'status' => $check[0], 'message' => $check[1],
            ])->values()->all(),
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
