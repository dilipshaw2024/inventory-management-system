<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\InvoiceDetail;
use App\Models\Purchase;
use Illuminate\Console\Command;

class BackfillInventoryLedger extends Command
{
    protected $signature = 'erp:backfill-inventory-ledger {--dry-run : Report rows without inserting them}';

    protected $description = 'Backfill immutable inventory movements from approved legacy purchases and invoices';

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');

        Purchase::where('status', 1)->orderBy('id')->chunkById(100, function ($purchases) use (&$created, &$skipped, $dryRun): void {
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
                    InventoryMovement::create([
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
                        'posted_at' => $purchase->updated_at ?: now(),
                    ]);
                }
                $created++;
            }
        });

        InvoiceDetail::where('status', 1)->with('product')->orderBy('id')->chunkById(100, function ($details) use (&$created, &$skipped, $dryRun): void {
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
                    InventoryMovement::create([
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
                        'posted_at' => $invoice->updated_at ?: now(),
                    ]);
                }
                $created++;
            }
        });

        $this->info(($dryRun ? 'Would create ' : 'Created ').$created.' movement(s); skipped '.$skipped.' existing movement(s).');
        return self::SUCCESS;
    }
}
