<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use App\Models\Supplier;

class PlanningCalendarService
{
    public function addWorkingDays(CarbonImmutable|string $start, int $days, ?int $companyId = null): CarbonImmutable
    {
        $calendar = app(ErpSettingService::class)->get('planning_calendar', [
            'weekend_days' => [0, 6],
            'holidays' => [],
        ], $companyId);

        return $this->addWorkingDaysWithCalendar($start, $days, is_array($calendar) ? $calendar : []);
    }

    public function addWorkingDaysForSupplier(CarbonImmutable|string $start, int $days, Supplier $supplier, ?int $companyId = null): CarbonImmutable
    {
        if (is_array($supplier->planning_calendar)) {
            return $this->addWorkingDaysWithCalendar($start, $days, $supplier->planning_calendar);
        }
        return $this->addWorkingDays($start, $days, $companyId);
    }

    public function addWorkingDaysWithCalendar(CarbonImmutable|string $start, int $days, array $calendar): CarbonImmutable
    {
        $date = $start instanceof CarbonImmutable ? $start->startOfDay() : CarbonImmutable::parse($start)->startOfDay();
        if ($days <= 0) return $date;

        $weekends = collect($calendar['weekend_days'] ?? [0, 6])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()->values()->all();
        $holidays = collect($calendar['holidays'] ?? [])
            ->map(fn ($holiday): string => (string) $holiday)
            ->filter(fn (string $holiday): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday) === 1)
            ->flip();

        $remaining = $days;
        while ($remaining > 0) {
            $date = $date->addDay();
            if (in_array($date->dayOfWeek, $weekends, true) || $holidays->has($date->toDateString())) continue;
            $remaining--;
        }
        return $date;
    }
}
