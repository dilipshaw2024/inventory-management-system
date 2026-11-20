<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InventoryExceptionNotification;
use App\Services\ErpSettingService;
use App\Services\InventoryAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendInventoryExceptionAlerts extends Command
{
    protected $signature = 'erp:inventory:exception-alerts
        {type : Exception type: excess, slow, or dead}
        {--days= : Override the aging window for slow/dead stock}
        {--company= : Limit alerts to one company ID}';

    protected $description = 'Notify authorized users about excess, slow-moving, or dead inventory';

    public function handle(): int
    {
        $type = strtolower(trim((string) $this->argument('type')));
        if (!in_array($type, ['excess', 'slow', 'dead'], true)) {
            $this->error('Type must be excess, slow, or dead.');
            return self::INVALID;
        }

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $daysOption = $this->option('days');
        $sent = 0;
        $products = Product::query()
            ->where('status', 1)
            ->where('is_stock_item', true)
            ->whereNotNull('company_id')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('company_id')->orderBy('id')->get();

        foreach ($products->groupBy('company_id') as $productCompanyId => $companyProducts) {
            $productCompanyId = (int) $productCompanyId;
            $availability = app(InventoryAvailabilityService::class)->availableMany($companyProducts, true, null, $productCompanyId);
            $days = max(1, min(3650, (int) ($daysOption ?? app(ErpSettingService::class)->get(
                $type === 'dead' ? 'dead_stock_days' : 'slow_moving_days',
                $type === 'dead' ? 180 : 90,
                $productCompanyId
            ))));
            $cutoff = Carbon::now()->subDays($days);
            $issueQuantities = in_array($type, ['slow', 'dead'], true)
                ? app(\App\Services\InventoryMovementMetricsService::class)->issueQuantitiesSince($companyProducts->pluck('id'), $cutoff, $productCompanyId)
                : collect();
            $velocityThreshold = (float) app(ErpSettingService::class)->get('slow_moving_velocity_threshold', 0, $productCompanyId);

            $users = User::query()
                ->where('company_id', $productCompanyId)
                ->where('is_active', true)
                ->with('roles.permissions')->get()
                ->filter(fn (User $user): bool => $user->hasPermission('reports.view') || $user->hasPermission('inventory.view'));

            foreach ($companyProducts as $product) {
                $available = (float) ($availability[$product->id] ?? 0);
                $matches = match ($type) {
                    'excess' => $product->max_stock !== null && $available > (float) $product->max_stock,
                    'slow' => app(\App\Services\InventoryMovementMetricsService::class)->isSlowMoving((float) ($issueQuantities[$product->id] ?? 0), $days, $velocityThreshold),
                    'dead' => $available > 0.000001 && (float) ($issueQuantities[$product->id] ?? 0) <= 0.000001,
                };
                if (!$matches) continue;

                $excessQuantity = $product->max_stock === null ? 0.0 : max(0, $available - (float) $product->max_stock);
                $data = [
                    'exception_type' => $type,
                    'product_id' => $product->id,
                    'product' => $product->name,
                    'sku' => $product->sku,
                    'available' => $available,
                    'max_stock' => $product->max_stock === null ? null : (float) $product->max_stock,
                    'excess_quantity' => $excessQuantity,
                    'value' => $available * (float) ($product->purchase_price ?? 0),
                    'days_without_issue' => $type === 'excess' ? null : $days,
                    'action_endpoint' => '/api/inventory/exceptions?type='.$type.'&product_id='.$product->id,
                    'action' => $type === 'excess' ? 'review_stock_rebalancing' : 'review_demand_or_disposition',
                ];

                foreach ($users as $user) {
                    $duplicate = $user->notifications()
                        ->where('type', InventoryExceptionNotification::class)
                        ->whereDate('created_at', Carbon::today())
                        ->whereJsonContains('data->product_id', $product->id)
                        ->whereJsonContains('data->exception_type', $type)
                        ->exists();
                    if ($duplicate) continue;
                    $user->notify(new InventoryExceptionNotification($data));
                    $sent++;
                }
            }
        }

        $this->info("Created {$sent} {$type} inventory exception alert(s).");
        return self::SUCCESS;
    }
}
