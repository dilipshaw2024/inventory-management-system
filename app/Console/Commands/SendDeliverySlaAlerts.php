<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\User;
use App\Notifications\DeliverySlaBreachNotification;
use App\Services\AuditService;
use App\Services\CarrierSlaPolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDeliverySlaAlerts extends Command
{
    protected $signature = 'erp:sales:delivery-sla-alerts {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify authorized sales users about deliveries that breached their carrier SLA.';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $now = now();
        $deliveries = Delivery::with(['salesOrder.customer', 'salesOrder.store', 'trackingEvents'])
            ->where('status', 'approved')->whereIn('fulfillment_status', ['dispatched', 'in_transit'])
            ->where(fn ($query) => $query->whereNull('company_id')->when($companyId, fn ($nested) => $nested->orWhere('company_id', $companyId)))
            ->get();
        $users = User::where('is_active', true)->with('roles.permissions')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->get()->filter(fn (User $user): bool => $user->hasPermission('sales.manage') || $user->hasPermission('reports.view'));
        $alerts = 0;
        foreach ($deliveries as $delivery) {
            $settingsCompanyId = (int) ($delivery->company_id ?: $companyId ?: 0);
            if (!$settingsCompanyId) continue;
            $slaHours = app(CarrierSlaPolicyService::class)->hoursFor($settingsCompanyId, $delivery->salesOrder?->store?->branch_id, $delivery->carrier);
            if (!$delivery->date || !$now->greaterThan(app(CarrierSlaPolicyService::class)->deadlineAt($delivery->date->toDateString(), $slaHours, $settingsCompanyId, $delivery->salesOrder?->store?->branch_id))) continue;
            $escalated = DB::transaction(function () use ($delivery, $now): ?array {
                $locked = Delivery::lockForUpdate()->findOrFail($delivery->id);
                if ($locked->status !== 'approved' || !in_array($locked->fulfillment_status, ['dispatched', 'in_transit'], true)) return null;
                if ($locked->last_sla_escalated_at && $locked->last_sla_escalated_at->greaterThanOrEqualTo($now->copy()->startOfDay())) return null;
                $before = $locked->only(['sla_breached_at', 'sla_escalation_level', 'last_sla_escalated_at']);
                $level = min(3, (int) $locked->sla_escalation_level + 1);
                $locked->update(['sla_breached_at' => $locked->sla_breached_at ?: $now, 'sla_escalation_level' => $level, 'last_sla_escalated_at' => $now]);
                app(AuditService::class)->record('delivery.sla_escalated', $locked, $before, $locked->fresh()->only(['sla_breached_at', 'sla_escalation_level', 'last_sla_escalated_at']) + ['escalation_level' => $level, 'automated' => true]);
                return [$level, $locked->fresh(['salesOrder.customer'])];
            });
            if (!$escalated) continue;
            [$level, $alerted] = $escalated;
            foreach ($users->where('company_id', $alerted->company_id)->unique('id') as $user) {
                $duplicate = $user->notifications()->where('type', DeliverySlaBreachNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->delivery_id', $alerted->id)->exists();
                if ($duplicate) continue;
                $user->notify(new DeliverySlaBreachNotification(['delivery_id' => $alerted->id, 'delivery_no' => $alerted->delivery_no, 'carrier' => $alerted->carrier, 'tracking_no' => $alerted->tracking_no, 'customer' => $alerted->salesOrder?->customer?->name, 'fulfillment_status' => $alerted->fulfillment_status, 'sla_escalation_level' => $level, 'sla_breached_at' => $alerted->sla_breached_at?->toISOString()]));
                $alerts++;
            }
        }
        $this->info("Created {$alerts} delivery SLA alert(s).");
        return self::SUCCESS;
    }
}
