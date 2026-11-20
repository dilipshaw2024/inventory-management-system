<?php

namespace App\Console\Commands;

use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Notifications\InventoryExpiryNotification;
use App\Services\ErpSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendInventoryExpiryAlerts extends Command
{
    protected $signature = 'erp:inventory:expiry-alerts {--days= : Override the company alert horizon in days} {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify authorized users about batches with positive stock that are expired or nearing expiry';

    public function handle(): int
    {
        $daysOverride = $this->option('days') !== null ? max(0, min(3650, (int) $this->option('days'))) : null;
        $query = InventoryBatch::with('product')->where(fn ($builder) => $builder->whereNotNull('expiry_date')->orWhereNotNull('best_before_date'));
        if ($this->option('company')) $query->whereHas('product', fn ($builder) => $builder->where('company_id', (int) $this->option('company')));

        $users = User::where('is_active', true)->with('roles.permissions')->get()->filter(
            fn (User $user): bool => $user->hasPermission('reports.view') || $user->hasPermission('inventory.view')
        );
        $sent = 0;
        foreach ($query->cursor() as $batch) {
            $companyId = (int) ($batch->product?->company_id ?? 0);
            $expiryDays = $daysOverride ?? (int) app(ErpSettingService::class)->get('expiry_alert_days', 90, $companyId);
            $bestBeforeDays = $daysOverride ?? (int) app(ErpSettingService::class)->get('best_before_alert_days', 90, $companyId);
            $today = Carbon::today();
            $expiryDue = $batch->expiry_date && $batch->expiry_date->lte($today->copy()->addDays($expiryDays));
            $bestBeforeDue = $batch->best_before_date && $batch->best_before_date->lte($today->copy()->addDays($bestBeforeDays));
            if (!$expiryDue && !$bestBeforeDue) continue;
            $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
            $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
            $balance = (float) InventoryMovement::query()->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')->whereRaw($allocationBatch.' = ?', [$batch->id])->selectRaw("COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ('receipt','opening','return_in','transfer_in','adjustment_in','quarantine_out','release') THEN {$allocationQuantity} ELSE -{$allocationQuantity} END), 0) AS balance")->value('balance');
            if ($balance <= 0.000001) continue;
            $valueAtRisk = (float) InventoryCostLayer::where('batch_id', $batch->id)
                ->where('remaining_quantity', '>', 0)
                ->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost), 0) AS value_at_risk')
                ->value('value_at_risk');
            if ($valueAtRisk <= 0.000001) $valueAtRisk = $balance * (float) ($batch->product?->purchase_price ?? 0);
            $riskDate = collect([$batch->expiry_date, $batch->best_before_date])->filter()->sortBy(fn ($date) => $date->timestamp)->first();
            foreach ($users->where('company_id', $batch->product->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', InventoryExpiryNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->batch_id', $batch->id)->exists();
                if ($duplicate) continue;
                $user->notify(new InventoryExpiryNotification([
                    'batch_id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'product' => $batch->product?->name,
                    'batch_no' => $batch->batch_no,
                    'expiry_date' => optional($batch->expiry_date)->toDateString(),
                    'best_before_date' => optional($batch->best_before_date)->toDateString(),
                    'balance' => $balance,
                    'value_at_risk' => $valueAtRisk,
                    'days_to_expiry' => $riskDate ? Carbon::today()->diffInDays($riskDate, false) : null,
                ]));
                $sent++;
            }
        }
        $this->info("Created {$sent} inventory expiry alert(s).");
        return self::SUCCESS;
    }
}
