<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliationService;
use Illuminate\Console\Command;

class ReconcileInventoryLedger extends Command
{
    protected $signature = 'erp:reconcile-inventory-ledger
        {--company= : Restrict reconciliation to one company ID}
        {--tolerance=0.000001 : Maximum acceptable quantity difference}
        {--fail-on-mismatch : Return a failure exit code when drift is found}
        {--apply : Synchronize legacy product quantities from ledger balances}
        {--reason= : Mandatory audit reason when applying synchronization}';

    protected $description = 'Report differences between legacy product quantities and immutable inventory ledger balances';

    public function handle(InventoryReconciliationService $reconciliation): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $tolerance = max(0, (float) $this->option('tolerance'));
        $reason = trim((string) $this->option('reason'));
        if ($this->option('apply') && $reason === '') {
            $this->error('The --reason option is required with --apply. No data was changed.');
            return self::FAILURE;
        }
        $rows = $reconciliation->rows($companyId, $tolerance);

        if ($rows->isEmpty()) {
            $this->info('Inventory ledger reconciliation passed; no quantity drift was found.');
            return self::SUCCESS;
        }

        $this->warn('Found '.$rows->count().' product balance mismatch(es).');
        $this->table(
            ['Product ID', 'SKU', 'Product', 'Legacy quantity', 'Ledger quantity', 'Difference'],
            $rows->take(100)->map(fn (array $row): array => [
                $row['product']->id,
                $row['product']->sku ?: '—',
                $row['product']->name,
                number_format($row['legacy_quantity'], 6, '.', ''),
                number_format($row['ledger_quantity'], 6, '.', ''),
                number_format($row['difference'], 6, '.', ''),
            ])->all()
        );
        if ($rows->count() > 100) $this->line('Only the first 100 mismatches are displayed.');

        if ($this->option('apply')) {
            $updated = $reconciliation->synchronizeLegacyBalances($companyId, $tolerance, $reason);
            $this->info("Synchronized {$updated} legacy product balance(s) from ledger history.");
        }

        return $this->option('fail-on-mismatch') ? self::FAILURE : self::SUCCESS;
    }
}
