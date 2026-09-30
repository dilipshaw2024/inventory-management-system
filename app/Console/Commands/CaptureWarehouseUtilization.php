<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\WarehouseUtilizationSnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CaptureWarehouseUtilization extends Command
{
    protected $signature = 'erp:warehouse:capture-utilization
        {--company= : Capture one company only}
        {--date= : As-of date (YYYY-MM-DD), defaults to today}';

    protected $description = 'Capture daily warehouse location utilization snapshots';

    public function handle(WarehouseUtilizationSnapshotService $snapshots): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        try {
            $date = Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (\Throwable) {
            $this->error('The utilization snapshot date must be valid and use YYYY-MM-DD format.');
            return self::FAILURE;
        }

        $companyOption = $this->option('company');
        $companies = $companyOption !== null
            ? Company::whereKey((int) $companyOption)->pluck('id')
            : Company::query()->pluck('id');

        if ($companies->isEmpty()) {
            $this->info('No companies found for utilization capture.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($companies as $companyId) {
            $count = $snapshots->capture((int) $companyId, $date)->count();
            $total += $count;
            $this->line(sprintf('Company %d: %d location snapshots captured for %s.', $companyId, $count, $date));
        }

        $this->info(sprintf('Warehouse utilization capture completed: %d snapshots.', $total));
        return self::SUCCESS;
    }
}
