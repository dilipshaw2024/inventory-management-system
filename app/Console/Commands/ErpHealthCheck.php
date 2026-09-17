<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ErpHealthCheck extends Command
{
    protected $signature = 'erp:system:health {--strict : Return failure when any check is unhealthy}';
    protected $description = 'Check ERP application, database, migration, and private storage readiness';

    public function handle(): int
    {
        $checks = [];
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok', 'reachable'];
            $checks['migrations'] = Schema::hasTable('migrations') ? ['ok', 'migration table available'] : ['fail', 'migration table is missing'];
        } catch (\Throwable $exception) {
            $checks['database'] = ['fail', $exception->getMessage()];
            $checks['migrations'] = ['fail', 'not checked because the database is unavailable'];
        }
        try {
            Storage::disk('local')->put('.erp-health-check', now()->toIso8601String());
            Storage::disk('local')->delete('.erp-health-check');
            $checks['private_storage'] = ['ok', 'writable'];
        } catch (\Throwable $exception) {
            $checks['private_storage'] = ['fail', $exception->getMessage()];
        }

        $failed = 0;
        foreach ($checks as $name => [$status, $message]) {
            $status === 'ok' ? $this->info("PASS  {$name}: {$message}") : $this->error("FAIL  {$name}: {$message}");
            $failed += $status === 'ok' ? 0 : 1;
        }
        if ($failed && !$this->option('strict')) {
            $this->warn('Health check found issues; use --strict for a failing exit code.');
            return self::SUCCESS;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
