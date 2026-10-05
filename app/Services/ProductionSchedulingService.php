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
                $candidateIds = array_values(array_unique(array_merge([(int) $operation->work_center_id], array_map('intval', $operation->routingOperation?->alternate_work_center_ids ?? []))));
                $centers = WorkCenter::whereIn('id', $candidateIds)->lockForUpdate()->get()->keyBy('id');
                $best = null;
                foreach ($candidateIds as $candidateId) {
                    $center = $centers->get($candidateId);
                    if (!$center || !$center->is_active) continue;
                    $candidateCursor = $cursor;
                    $occupiedUntil = ProductionOperation::where('work_center_id', $center->id)->where('production_order_id', '!=', $order->id)->whereNotIn('status', ['completed', 'skipped', 'cancelled'])->whereNotNull('scheduled_end_at')->where('scheduled_end_at', '>', $candidateCursor)->max('scheduled_end_at');
                    if ($occupiedUntil) $candidateCursor = max($candidateCursor, $this->date($occupiedUntil));
                    [$candidateStart, $candidateEnd] = $this->slot($candidateCursor, $this->durationMinutes($operation), (float) $center->capacity_hours_per_day, (array) ($center->calendar ?? []));
                    if ($best === null || $candidateStart->lessThan($best['start']) || ($candidateStart->equalTo($best['start']) && ($candidateEnd->lessThan($best['end']) || ($candidateEnd->equalTo($best['end']) && $center->id < $best['center']->id)))) $best = ['center' => $center, 'start' => $candidateStart, 'end' => $candidateEnd];
                }
                if ($best === null) throw new \RuntimeException('Every schedulable operation requires an active work center.');
                $operation->update(['work_center_id' => $best['center']->id, 'scheduled_start_at' => $best['start'], 'scheduled_end_at' => $best['end'], 'schedule_status' => 'scheduled']);
                $cursor = $best['end'];
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
        $cursor = $this->date($start);
        $scheduledStart = null;
        $remaining = $minutes;
        while (true) {
            $day = $this->nextWorkingDay($cursor->startOfDay(), $calendar);
            foreach ($this->shiftWindows($day, $capacityHours, $calendar) as [$opening, $closing]) {
                if ($cursor->greaterThanOrEqualTo($closing)) continue;
                $dayStart = $cursor->greaterThan($opening) ? $cursor : $opening;
                $available = max(0, $dayStart->diffInMinutes($closing));
                if ($available <= 0) continue;
                if ($remaining <= 0.000001) return [$dayStart, $dayStart];
                if ($scheduledStart === null) $scheduledStart = $dayStart;
                $used = min($remaining, $available);
                $end = $dayStart->addMinutes((int) round($used));
                $remaining -= $used;
                if ($remaining <= 0.000001) return [$scheduledStart, $end];
                $cursor = $closing;
            }
            $cursor = $day->addDay()->startOfDay();
        }
    }

    private function shiftWindows(CarbonImmutable $day, float $capacityHours, array $calendar): array
    {
        $shiftDefinitions = $calendar['shifts'] ?? null;
        if ($shiftDefinitions !== null) {
            if (!is_array($shiftDefinitions) || $shiftDefinitions === []) throw new \InvalidArgumentException('Work-center shifts must contain at least one window.');
            $windows = [];
            foreach ($shiftDefinitions as $shift) {
                if (!is_array($shift)) throw new \InvalidArgumentException('Work-center shifts must be arrays with start and end times.');
                $openingMinutes = $this->timeMinutes((string) ($shift['start'] ?? ''));
                $closingMinutes = $this->timeMinutes((string) ($shift['end'] ?? ''));
                if ($closingMinutes <= $openingMinutes) throw new \InvalidArgumentException('Work-center shift end must be after shift start on the same day.');
                $windows[] = [$day->startOfDay()->addMinutes($openingMinutes), $day->startOfDay()->addMinutes($closingMinutes)];
            }
            usort($windows, fn (array $left, array $right): int => $left[0]->getTimestamp() <=> $right[0]->getTimestamp());
            for ($index = 1; $index < count($windows); $index++) {
                if ($windows[$index][0]->lessThan($windows[$index - 1][1])) throw new \InvalidArgumentException('Work-center shift windows cannot overlap.');
            }
            return $windows;
        }
        $dailyMinutes = $capacityHours * 60;
        $openingMinutes = $this->timeMinutes((string) ($calendar['shift_start'] ?? '08:00'));
        $closingMinutes = $this->timeMinutes((string) ($calendar['shift_end'] ?? sprintf('%02d:%02d', intdiv($openingMinutes + (int) $dailyMinutes, 60) % 24, ($openingMinutes + (int) $dailyMinutes) % 60)));
        if ($closingMinutes <= $openingMinutes) throw new \InvalidArgumentException('Work-center shift end must be after shift start on the same day.');
        return [[$day->startOfDay()->addMinutes($openingMinutes), $day->startOfDay()->addMinutes($closingMinutes)]];
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
