<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\InvoiceDetail;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Services\AutomaticAccountingService;
use App\Services\InventoryCostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInventoryLedger extends Command
{
    protected $signature = 'erp:backfill-inventory-ledger
        {--dry-run : Report rows without inserting them}
        {--with-costing : Also rebuild cost layers, consumptions, and movement allocations for newly created rows}
        {--with-accounting : Also post mapped inventory journals for newly created costed rows (requires --with-costing)}';

    protected $description = 'Backfill immutable inventory movements from approved legacy purchases and invoices';

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');
        $withCosting = (bool) $this->option('with-costing');
        $withAccounting = (bool) $this->option('with-accounting');
        if ($withAccounting && !$withCosting) {
            $this->error('--with-accounting requires --with-costing so journal values are based on immutable cost allocations.');
            return self::INVALID;
        }
        $costing = app(InventoryCostingService::class);
        $accounting = app(AutomaticAccountingService::class);
        $accountingCreated = 0;
        $accountingMissing = 0;

        Purchase::where('status', 1)->orderBy('id')->chunkById(100, function ($purchases) use (&$created, &$skipped, &$accountingCreated, &$accountingMissing, $dryRun, $withCosting, $withAccounting, $costing, $accounting): void {
            foreach ($purchases as $purchase) {
                $exists = InventoryMovement::where('movement_type', 'receipt')
                    ->where('reference_type', $purchase->getMorphClass())
                    ->where('reference_id', $purchase->id)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                if (!$dryRun) {
                    DB::transaction(function () use ($purchase, $withCosting, $withAccounting, $costing, $accounting, &$accountingCreated, &$accountingMissing): void {
                        $postedAt = $purchase->date ?: ($purchase->updated_at ?: now()->toDateString());
                        $movement = InventoryMovement::create([
                            'company_id' => $purchase->company_id,
                            'product_id' => $purchase->product_id,
                            'movement_type' => 'receipt',
                            'quantity' => $purchase->buying_qty,
                            'unit_cost' => $purchase->unit_price,
                            'reference_type' => $purchase->getMorphClass(),
                            'reference_id' => $purchase->id,
                            'reference_no' => $purchase->purchase_no,
                            'reason' => 'Legacy approved purchase backfill',
                            'created_by' => $purchase->updated_by ?: $purchase->created_by,
                            'posted_at' => $postedAt,
                        ]);
                        if ($withCosting) {
                            $costing->receipt((int) $purchase->product_id, (float) $purchase->buying_qty, (float) $purchase->unit_price, null, null, $movement);
                            if ($withAccounting) {
                                $accounting->postInventoryMovement($movement, (float) $purchase->buying_qty * (float) $purchase->unit_price, $purchase);
                                if (JournalEntry::where('source_type', $movement->getMorphClass())->where('source_id', $movement->id)->exists()) $accountingCreated++;
                                else $accountingMissing++;
                            }
                        }
                    });
                }
                $created++;
            }
        });

        InvoiceDetail::where('status', 1)->with('product')->orderBy('id')->chunkById(100, function ($details) use (&$created, &$skipped, &$accountingCreated, &$accountingMissing, $dryRun, $withCosting, $withAccounting, $costing, $accounting): void {
            foreach ($details as $detail) {
                $exists = InventoryMovement::where('movement_type', 'issue')
                    ->where('reference_type', $detail->getMorphClass())
                    ->where('reference_id', $detail->id)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                $invoice = $detail->invoice;
                if (!$dryRun) {
                    DB::transaction(function () use ($detail, $invoice, $withCosting, $withAccounting, $costing, $accounting, &$accountingCreated, &$accountingMissing): void {
                        $postedAt = $detail->date ?: ($invoice->date ?: ($invoice->updated_at ?: now()->toDateString()));
                        $movement = InventoryMovement::create([
                            'company_id' => $invoice->company_id,
                            'product_id' => $detail->product_id,
                            'movement_type' => 'issue',
                            'quantity' => $detail->selling_qty,
                            'unit_cost' => $detail->unit_price,
                            'reference_type' => $detail->getMorphClass(),
                            'reference_id' => $detail->id,
                            'reference_no' => $invoice->invoice_no,
                            'reason' => 'Legacy approved invoice backfill',
                            'created_by' => $invoice->updated_by ?: $invoice->created_by,
                            'posted_at' => $postedAt,
                        ]);
                        if ($withCosting) {
                            $totalCost = $costing->consume((int) $detail->product_id, (float) $detail->selling_qty, null, $movement, (float) $detail->unit_price, $detail->batch_id);
                            $movement->finalizeUnitCost($totalCost / max((float) $detail->selling_qty, 0.000001));
                            if ($withAccounting) {
                                $accounting->postInventoryMovement($movement, $totalCost, $detail);
                                if (JournalEntry::where('source_type', $movement->getMorphClass())->where('source_id', $movement->id)->exists()) $accountingCreated++;
                                else $accountingMissing++;
                            }
                        }
                    });
                }
                $created++;
            }
        });

        $mode = $withCosting ? ' with costing rebuild' : '';
        $accountingMode = $withAccounting ? ' and accounting' : '';
        $this->info(($dryRun ? 'Would create ' : 'Created ').$created.' movement(s)'.$mode.$accountingMode.'; skipped '.$skipped.' existing movement(s).');
        if ($withAccounting && !$dryRun) $this->info('Accounting journals posted: '.$accountingCreated.'; missing account mappings: '.$accountingMissing.'.');
        return self::SUCCESS;
    }
}
