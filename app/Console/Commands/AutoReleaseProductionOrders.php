<?php

namespace App\Console\Commands;

use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Services\ErpSettingService;
use App\Services\ProductionService;
use Illuminate\Console\Command;

class AutoReleaseProductionOrders extends Command
{
    protected $signature = 'erp:planning:auto-release-production-orders {--company= : Limit processing to a company ID} {--horizon-days=0 : Release draft orders planned through this many days ahead} {--force : Run even when the company setting is disabled} {--dry-run : Report eligible orders without releasing them}';
    protected $description = 'Release eligible planned production orders while preserving stock and approval controls.';

    public function handle(): int
    {
        $horizon = max(0, min(365, (int) $this->option('horizon-days')));
        $through = now()->addDays($horizon)->toDateString();
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey((int) $id))->pluck('id');
        $released = 0; $skipped = 0;

        foreach ($companies as $companyId) {
            $enabled = app(ErpSettingService::class)->get('auto_release_production_orders', false, (int) $companyId);
            if (!$enabled && !$this->option('force')) {
                $this->line('Company '.$companyId.': automatic production release is disabled.');
                continue;
            }
            $orders = ProductionOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'draft')
                ->whereDate('planned_date', '<=', $through)->orderBy('planned_date')->orderBy('id')->get();
            foreach ($orders as $order) {
                $hasApprovalPolicy = ApprovalPolicy::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->where('document_type', $order->getMorphClass())->where('is_active', true)->exists();
                if ($hasApprovalPolicy) {
                    $this->line('Skipped '.$order->order_no.': an approval policy requires an interactive checker.');
                    $skipped++;
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line('Eligible '.$order->order_no.' planned '.$order->planned_date?->toDateString().'.');
                    continue;
                }
                try {
                    app(ProductionService::class)->release((int) $order->id, (int) $companyId);
                    $released++;
                } catch (\Throwable $exception) {
                    $this->warn('Skipped '.$order->order_no.': '.$exception->getMessage());
                    $skipped++;
                }
            }
            $this->info('Company '.$companyId.': '.($this->option('dry-run') ? 'dry-run completed.' : $released.' order(s) released.'));
        }

        $this->info('Automatic production release complete: '.$released.' released, '.$skipped.' skipped.');
        return self::SUCCESS;
    }
}
