<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ErpHealthCheck extends Command
{
    protected $signature = 'erp:system:health {--strict : Return failure when any check is unhealthy} {--json : Output machine-readable JSON}';
    protected $description = 'Check ERP application, database schema, migrations, and private storage readiness';

    public function handle(): int
    {
        $checks = [];
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
            $missingTables = collect($requiredTables)->reject(fn (string $table): bool => Schema::hasTable($table))->values()->all();
            $checks['erp_schema'] = empty($missingTables)
                ? ['ok', count($requiredTables).' core ERP tables available']
                : ['fail', 'missing tables: '.implode(', ', $missingTables)];
        } catch (\Throwable $exception) {
            $checks['database'] = ['fail', $exception->getMessage()];
            $checks['migrations'] = ['fail', 'not checked because the database is unavailable'];
            $checks['erp_schema'] = ['fail', 'not checked because the database is unavailable'];
        }
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

        $failed = collect($checks)->filter(fn (array $check): bool => $check[0] !== 'ok')->count();
        if ($this->option('json')) {
            $payload = [
                'status' => $failed === 0 ? 'healthy' : 'unhealthy',
                'checks' => collect($checks)->map(fn (array $check, string $name): array => [
                    'name' => $name, 'status' => $check[0], 'message' => $check[1],
                ])->values()->all(),
                'checked_at' => now()->toIso8601String(),
            ];
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $failed && $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }
        foreach ($checks as $name => [$status, $message]) {
            $status === 'ok' ? $this->info("PASS  {$name}: {$message}") : $this->error("FAIL  {$name}: {$message}");
        }
        if ($failed && !$this->option('strict')) {
            $this->warn('Health check found issues; use --strict for a failing exit code.');
            return self::SUCCESS;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
