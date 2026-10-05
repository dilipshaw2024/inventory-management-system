<?php

namespace App\Console\Commands;

use App\Models\CarrierTrackingProviderSetting;
use App\Models\Delivery;
use App\Services\CarrierTrackingSyncService;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\CarrierTrackingPoller;
use Illuminate\Console\Command;
use Throwable;

class SyncCarrierTracking extends Command
{
    protected $signature = 'erp:logistics:sync-carrier-tracking
        {--company= : Limit polling to one company ID}
        {--provider= : Limit polling to one provider key}
        {--delivery= : Poll one delivery ID}
        {--limit=100 : Maximum deliveries per provider run}';

    protected $description = 'Poll configured carrier providers for dispatched deliveries and record tracking events';

    public function handle(CarrierTrackingAdapterRegistry $adapters, CarrierTrackingSyncService $sync): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $providerFilter = $this->option('provider') ? strtolower(trim((string) $this->option('provider'))) : null;
        $deliveryId = $this->option('delivery') ? (int) $this->option('delivery') : null;
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $processed = 0;
        $recorded = 0;
        $duplicates = 0;
        $failed = 0;

        $settings = CarrierTrackingProviderSetting::query()
            ->where('is_active', true)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($providerFilter, fn ($query) => $query->where('provider', $providerFilter))
            ->orderBy('company_id')->orderBy('provider')->get();

        foreach ($settings as $setting) {
            try {
                $adapter = $adapters->resolve((string) $setting->provider);
            } catch (Throwable $exception) {
                $this->warn("Company {$setting->company_id}: {$exception->getMessage()}");
                $failed++;
                continue;
            }
            if (!$adapter instanceof CarrierTrackingPoller) {
                $this->line("Skipped company {$setting->company_id}/{$setting->provider}: provider does not support polling.");
                continue;
            }

            $deliveries = Delivery::query()
                ->where('company_id', $setting->company_id)
                ->where('status', 'approved')
                ->whereNotIn('fulfillment_status', ['delivered', 'cancelled'])
                ->whereNotNull('tracking_no')
                ->whereHas('operations', fn ($query) => $query->where('operation_type', 'dispatch'))
                ->when($deliveryId, fn ($query) => $query->whereKey($deliveryId))
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($deliveries as $delivery) {
                $processed++;
                try {
                    $result = $sync->sync($delivery, (string) $setting->provider);
                    $recorded += (int) $result['recorded'];
                    $duplicates += (int) $result['duplicates'];
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn("Delivery {$delivery->id}: {$exception->getMessage()}");
                }
            }
        }

        $this->info("Carrier tracking polling complete: {$processed} deliveries, {$recorded} events recorded, {$duplicates} duplicates, {$failed} failures.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
