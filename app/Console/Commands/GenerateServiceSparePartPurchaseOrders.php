<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ServiceSparePartReplenishmentService;
use Illuminate\Console\Command;

class GenerateServiceSparePartPurchaseOrders extends Command
{
    protected $signature = 'erp:service:generate-spare-part-purchase-orders {--company= : Limit generation to a company ID} {--dry-run : Report recommendations without creating purchase orders}';
    protected $description = 'Generate approval-pending purchase orders for service spare-part shortages.';

    public function handle(ServiceSparePartReplenishmentService $replenishment): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))->pluck('id');
        foreach ($companies as $companyId) {
            $recommendations = $replenishment->recommendations((int) $companyId);
            if ($recommendations->isEmpty()) {
                $this->info('Company '.$companyId.': no service spare-part shortage found.');
                continue;
            }
            $externalReference = 'service-spare-parts-'.now()->toDateString().'-'.$companyId;
            if ($this->option('dry-run')) {
                $this->info('Company '.$companyId.': '.$recommendations->count().' service spare-part proposal(s).');
                foreach ($recommendations as $recommendation) $this->line('  '.$recommendation['product']->name.' — '.$recommendation['suggested_quantity'].' units');
                continue;
            }
            try {
                $result = $replenishment->createPurchaseOrders(
                    (int) $companyId,
                    $recommendations->map(fn (array $row): array => ['asset_spare_part_id' => $row['asset_spare_part_id'], 'quantity' => $row['suggested_quantity']])->all(),
                    $externalReference,
                    now()->toDateString(),
                    null,
                );
                $this->info('Company '.$companyId.': '.($result['existing_only'] ? 'reused' : 'created').' '.$result['orders']->count().' approval-pending service spare-part purchase order(s).');
            } catch (\Throwable $exception) {
                report($exception);
                $this->error('Company '.$companyId.': '.$exception->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
