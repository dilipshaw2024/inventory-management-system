<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Services\TransferReplenishmentService;
use Illuminate\Console\Command;

class GenerateTransferOrders extends Command
{
    protected $signature = 'erp:planning:generate-transfer-orders {--company= : Limit generation to a company ID} {--dry-run : Report proposed transfers without creating them}';
    protected $description = 'Create approval-pending warehouse transfers for inter-location replenishment suggestions.';

    public function handle(TransferReplenishmentService $transfers): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))->pluck('id');
        foreach ($companies as $companyId) {
            $created = 0; $skipped = 0;
            foreach ($transfers->suggestionsForCompany((int) $companyId) as $suggestion) {
                $externalReference = 'replenishment-transfer-'.now()->toDateString().'-'.$suggestion['product_id'].'-'.$suggestion['source_location_id'].'-'.$suggestion['destination_location_id'];
                if (InventoryTransfer::where('company_id', $companyId)->where('external_reference', $externalReference)->exists()) { $skipped++; continue; }
                if ($this->option('dry-run')) {
                    $this->line('Company '.$companyId.': '.$suggestion['product']->name.' — '.$suggestion['quantity'].' unit(s) '.$suggestion['source_location_code'].' -> '.$suggestion['destination_location_code']);
                    continue;
                }
                $transfers->createPendingTransfer($suggestion, (int) $companyId, null, $externalReference, null, 'Scheduled replenishment transfer; approval required.');
                $created++;
            }
            $this->info('Company '.$companyId.': '.($this->option('dry-run') ? 'dry-run completed.' : $created.' pending transfer(s) created; '.$skipped.' existing transfer(s) skipped.'));
        }
        return self::SUCCESS;
    }
}
