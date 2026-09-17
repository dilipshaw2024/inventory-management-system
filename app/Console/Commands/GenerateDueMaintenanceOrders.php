<?php

namespace App\Console\Commands;

use App\Models\MaintenanceOrder;
use App\Models\ServiceMaintenanceSchedule;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateDueMaintenanceOrders extends Command
{
    protected $signature = 'erp:service:generate-due {--company= : Limit generation to a company ID}';
    protected $description = 'Generate preventive maintenance orders for due active schedules.';

    public function handle(): int
    {
        $query = ServiceMaintenanceSchedule::query()->with('asset')->where('is_active', true)->where(function ($query): void {
            $query->whereDate('next_due', '<=', Carbon::today())->orWhere(function ($meter): void {
                $meter->whereNotNull('meter_interval')->whereNotNull('next_meter_due')->whereHas('asset', fn ($asset) => $asset->whereColumn('meter_value', '>=', 'service_maintenance_schedules.next_meter_due'));
            });
        });
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));

        $generated = 0;
        foreach ($query->pluck('id') as $scheduleId) {
            try {
                $created = DB::transaction(function () use ($scheduleId): bool {
                    $schedule = ServiceMaintenanceSchedule::lockForUpdate()->with('asset')->find($scheduleId);
                    if (!$schedule || !$schedule->is_active) return false;
                    $calendarDue = $schedule->next_due && !$schedule->next_due->isFuture();
                    $meterDue = $schedule->meter_interval !== null && $schedule->next_meter_due !== null && $schedule->asset?->meter_value !== null && (float) $schedule->asset->meter_value >= (float) $schedule->next_meter_due;
                    if (!$calendarDue && !$meterDue) return false;
                    $scheduledDate = $calendarDue ? $schedule->next_due : Carbon::today();
                    $order = MaintenanceOrder::create([
                        'company_id' => $schedule->company_id,
                        'order_no' => app(NumberingSequenceService::class)->nextOrFallback('maintenance_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), $schedule->company_id, null),
                        'asset_id' => $schedule->asset_id,
                        'maintenance_type' => 'preventive',
                        'scheduled_date' => $scheduledDate,
                        'assigned_to' => $schedule->assigned_to,
                        'notes' => $schedule->notes,
                        'created_by' => null,
                    ]);
                    $before = ['next_due' => $schedule->next_due?->toDateString(), 'next_meter_due' => $schedule->next_meter_due];
                    $updates = ['last_generated_at' => now()];
                    if ($calendarDue) $updates['next_due'] = $schedule->next_due->copy()->addDays($schedule->frequency_days);
                    if ($meterDue) $updates['next_meter_due'] = (float) $schedule->next_meter_due + (float) $schedule->meter_interval;
                    $schedule->update($updates);
                    app(AuditService::class)->record('maintenance_schedule.generated', $schedule, $before, $schedule->only(['next_due', 'next_meter_due']) + ['maintenance_order_id' => $order->id, 'automated' => true]);
                    return true;
                });
                if ($created) $generated++;
            } catch (\Throwable $exception) {
                $this->warn('Schedule '.$scheduleId.' skipped: '.$exception->getMessage());
            }
        }

        $this->info("Generated {$generated} preventive maintenance order(s).");
        return self::SUCCESS;
    }
}
