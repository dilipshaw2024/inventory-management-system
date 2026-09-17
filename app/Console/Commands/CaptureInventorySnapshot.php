<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\InventoryReconciliationSnapshot;
use App\Models\Product;
use App\Services\InventorySnapshotService;
use Illuminate\Console\Command;

class CaptureInventorySnapshot extends Command
{
    protected $signature = 'erp:inventory:capture-snapshot {--company= : Capture one company only} {--date= : As-of date (YYYY-MM-DD), defaults to today}';
    protected $description = 'Capture an immutable, date-bounded inventory balance snapshot';

    public function handle(InventorySnapshotService $snapshots): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        if (!strtotime($date)) { $this->error('The snapshot date must be a valid date.'); return self::FAILURE; }
        $companyOption = $this->option('company');
        $companies = $companyOption !== null
            ? collect([(int) $companyOption])
            : InventoryMovement::withoutGlobalScopes()->whereNotNull('company_id')->distinct()->pluck('company_id')
                ->merge(Product::withoutGlobalScopes()->whereNotNull('company_id')->distinct()->pluck('company_id'))->unique()->values();
        if ($companies->isEmpty()) $companies = collect([null]);
        foreach ($companies as $companyId) {
            $snapshot = $snapshots->capture($companyId === null ? null : (int) $companyId, $date, null);
            $this->line(sprintf('Company %s: snapshot #%d (%s, %d lines, hash %s)', $companyId ?? 'global', $snapshot->id, $snapshot->status, $snapshot->line_count, $snapshot->snapshot_hash));
        }
        $this->info('Inventory snapshot capture completed.');
        return self::SUCCESS;
    }
}
