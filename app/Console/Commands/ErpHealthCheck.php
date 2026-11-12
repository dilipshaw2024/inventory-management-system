<?php

namespace App\Console\Commands;

use App\Services\ErpHealthService;
use Illuminate\Console\Command;

class ErpHealthCheck extends Command
{
    protected $signature = 'erp:system:health {--strict : Return failure when any check is unhealthy} {--json : Output machine-readable JSON}';
    protected $description = 'Check ERP application, database schema, migrations, and private storage readiness';

    public function handle(ErpHealthService $health): int
    {
        $result = $health->check();
        $checks = collect($result['checks'])->mapWithKeys(fn (array $check): array => [$check['name'] => [$check['status'], $check['message']]])->all();
        $failed = collect($checks)->filter(fn (array $check): bool => $check[0] !== 'ok')->count();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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
