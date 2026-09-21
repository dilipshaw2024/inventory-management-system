<?php

namespace App\Services;

use App\Models\ProductionOperation;
use App\Models\ProductionOrder;
use App\Models\WorkCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ProductionSchedulingService
{
    public function scheduleMany(iterable $orders, CarbonImmutable|string|null $start = null, string $dispatchRule = 'planned_date'): Collection
    {
        if (!in_array($dispatchRule, ['planned_date', 'shortest_processing_time', 'critical_ratio'], true)) {
            throw new \InvalidArgumentException('Unsupported production dispatch rule.');
        }
        return DB::transaction(function () use ($orders, $start, $dispatchRule): Collection {
            $ordered = collect($orders)->values();
            if ($ordered->isEmpty()) return collect();
            $base = $start ?: CarbonImmutable::parse($ordered->first()->planned_date?->toDateString() ?: now()->toDateString())->startOfDay();
            $ordered->each(function (ProductionOrder $order): void {
                $order->loadMissing('operations.routingOperation');
                if ($order->operations->isEmpty()) {
                    app(ProductionOperationService::class)->initialize($order);
                    $order->load('operations.routingOperation');
                }
            });
            $ordered = $ordered->sortBy(function (ProductionOrder $order) use ($base, $dispatchRule): array {
                $date = $order->planned_date?->toDateString() ?? '9999-12-31';
                $duration = max(0.000001, $order->operations->sum(fn ($operation): float => $this->durationMinutes($operation)));
                $priority = match ($dispatchRule) {
                    'shortest_processing_time' => $duration,
                    'critical_ratio' => max(0.0, $base->diffInMinutes(CarbonImmutable::parse($date), false)) / $duration,
                    default => 0.0,
                };
                return [$priority, $date, str_pad((string) $order->id, 12, '0', STR_PAD_LEFT)];
            })->values();
            return $ordered->map(fn (ProductionOrder $order): ProductionOrder => $this->schedule($order, $base))->values();
        });
    }

    public function schedule(ProductionOrder $order, CarbonImmutable|string|null $start = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $start): ProductionOrder {
            $order = ProductionOrder::with('operations.routingOperation')->lockForUpdate()->findOrFail($order->getKey());
            if (in_array($order->status, ['completed', 'closed', 'cancelled', 'paused'], true)) throw new \RuntimeException('Completed, closed, cancelled, or paused production orders cannot be scheduled.');
            if ($order->operations->isEmpty()) {
                app(ProductionOperationService::class)->initialize($order);
                $order->load('operations.routingOperation');
            }
            $cursor = $start ? $this->date($start) : CarbonImmutable::parse($order->planned_date?->toDateString() ?: now()->toDateString())->startOfDay();
            foreach ($order->operations->sortBy('sequence') as $operation) {
                if (in_array($operation->status, ['completed', 'skipped', 'cancelled'], true)) continue;
                $center = WorkCenter::whereKey($operation->work_center_id)->lockForUpdate()->first();
                if (!$center || !$center->is_active) throw new \RuntimeException('Every schedulable operation requires an active work center.');
                $occupiedUntil = ProductionOperation::where('work_center_id', $center->id)->where('production_order_id', '!=', $order->id)->whereNotIn('status', ['completed', 'skipped', 'cancelled'])->whereNotNull('scheduled_end_at')->where('scheduled_end_at', '>', $cursor)->max('scheduled_end_at');
                if ($occupiedUntil) $cursor = max($cursor, $this->date($occupiedUntil));
                [$scheduledStart, $scheduledEnd] = $this->slot($cursor, $this->durationMinutes($operation), (float) $center->capacity_hours_per_day, (array) ($center->calendar ?? []));
                $operation->update(['scheduled_start_at' => $scheduledStart, 'scheduled_end_at' => $scheduledEnd, 'schedule_status' => 'scheduled']);
                $cursor = $scheduledEnd;
            }
            app(AuditService::class)->record('production_order.scheduled', $order, null, ['production_order_id' => $order->id]);
            return $order->fresh('operations.workCenter');
        });
    }

    public function durationMinutes(ProductionOperation $operation): float
    {
        return (float) ($operation->routingOperation?->setup_minutes ?? 0) + ((float) ($operation->routingOperation?->run_minutes ?? 0) * (float) $operation->planned_quantity);
    }

    public function slot(CarbonImmutable|string $start, float $minutes, float $capacityHours, array $calendar = []): array
    {
        if ($minutes < 0 || $capacityHours <= 0) throw new \InvalidArgumentException('Schedule duration and work-center capacity must be positive.');
        $cursor = $this->nextWorkingDay($this->date($start), $calendar);
        $dailyMinutes = $capacityHours * 60;
        $openingMinutes = $this->timeMinutes((string) ($calendar['shift_start'] ?? '08:00'));
        $closingMinutes = $this->timeMinutes((string) ($calendar['shift_end'] ?? sprintf('%02d:%02d', intdiv($openingMinutes + (int) $dailyMinutes, 60) % 24, ($openingMinutes + (int) $dailyMinutes) % 60)));
        if ($closingMinutes <= $openingMinutes) throw new \InvalidArgumentException('Work-center shift end must be after shift start on the same day.');
        if ($minutes <= 0.000001) {
            $opening = $cursor->startOfDay()->addMinutes($openingMinutes);
            return [$opening, $opening];
        }
        $remaining = $minutes; $scheduledStart = null;
        while ($remaining > 0.000001) {
            $cursor = $this->nextWorkingDay($cursor, $calendar);
            $openingMinutes = $this->timeMinutes((string) ($calendar['shift_start'] ?? '08:00'));
            $opening = $cursor->startOfDay()->addMinutes($openingMinutes);
            $dayStart = $cursor->greaterThan($opening) ? $cursor : $opening;
            $elapsed = max(0, ($dayStart->hour * 60 + $dayStart->minute) - $openingMinutes);
            $available = max(0, min($dailyMinutes, $closingMinutes - $openingMinutes - $elapsed));
            if ($available <= 0.000001) {
                $cursor = $cursor->addDay()->startOfDay();
                continue;
            }
            if ($scheduledStart === null) $scheduledStart = $dayStart;
            $used = min($remaining, $available);
            $end = $dayStart->addMinutes((int) round($used));
            $remaining -= $used;
            if ($remaining <= 0.000001) return [$scheduledStart, $end];
            $cursor = $cursor->addDay()->startOfDay();
        }
        return [$scheduledStart, $scheduledStart];
    }

    private function nextWorkingDay(CarbonImmutable $date, array $calendar): CarbonImmutable
    {
        $weekends = collect($calendar['weekend_days'] ?? [0, 6])->map(fn ($day): int => (int) $day)->all(); $holidays = collect($calendar['holidays'] ?? [])->map(fn ($day): string => (string) $day)->flip();
        while (in_array($date->dayOfWeek, $weekends, true) || $holidays->has($date->toDateString())) $date = $date->addDay()->startOfDay();
        return $date;
    }

    private function date(CarbonImmutable|string $date): CarbonImmutable { return $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date); }

    private function timeMinutes(string $time): int
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new \InvalidArgumentException('Work-center shift times must use HH:MM format.');
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }
}
