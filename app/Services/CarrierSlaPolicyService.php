<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class CarrierSlaPolicyService
{
    public function hoursFor(int $companyId, ?int $branchId, ?string $carrier, float $fallback = 48): float
    {
        $carrierKey = strtolower(trim((string) ($carrier ?: 'unassigned')));
        $branchPolicies = $this->map($this->settings->get('branch_carrier_sla_hours', [], $companyId));
        if ($branchId !== null) {
            $branch = $branchPolicies[(string) $branchId] ?? $branchPolicies[$branchId] ?? [];
            if (is_array($branch) && array_key_exists($carrierKey, $this->normalizeCarrierMap($branch))) return (float) $this->normalizeCarrierMap($branch)[$carrierKey];
        }
        $company = $this->normalizeCarrierMap($this->settings->get('carrier_sla_hours', [], $companyId));
        return (float) ($company[$carrierKey] ?? $fallback);
    }

    public function calendarFor(int $companyId, ?int $branchId): ?array
    {
        $branchCalendars = $this->settings->get('branch_sla_calendars', [], $companyId);
        if ($branchId !== null && is_array($branchCalendars) && is_array($branchCalendars[(string) $branchId] ?? null)) return $this->normalizeCalendar($branchCalendars[(string) $branchId]);
        $companyCalendar = $this->settings->get('sla_calendar', null, $companyId);
        return is_array($companyCalendar) ? $this->normalizeCalendar($companyCalendar) : null;
    }

    public function deadlineAt(CarbonImmutable|string $start, float $hours, int $companyId, ?int $branchId = null): CarbonImmutable
    {
        $cursor = $start instanceof CarbonImmutable ? $start : CarbonImmutable::parse($start);
        $calendar = $this->calendarFor($companyId, $branchId);
        if (!$calendar || $hours <= 0) return $cursor->addMinutes(max(0, $hours * 60));
        $remaining = $hours * 60;
        while ($remaining > 0.000001) {
            $day = $cursor->startOfDay();
            if ($this->isWorkingDay($day, $calendar)) {
                $opening = $day->addMinutes($calendar['shift_start_minutes']);
                $closing = $day->addMinutes($calendar['shift_end_minutes']);
                $current = $cursor->greaterThan($opening) ? $cursor : $opening;
                if ($current->lessThan($closing)) {
                    $available = $current->diffInMinutes($closing);
                    if ($remaining <= $available) return $current->addMinutes((int) ceil($remaining));
                    $remaining -= $available;
                }
            }
            $cursor = $day->addDay()->startOfDay();
        }
        return $cursor;
    }

    public function isBreached(CarbonImmutable|string $start, CarbonImmutable|string $at, float $hours, int $companyId, ?int $branchId = null): bool
    {
        $observed = $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse($at);
        return $observed->greaterThan($this->deadlineAt($start, $hours, $companyId, $branchId));
    }

    public function normalize(array $policies): array
    {
        $normalized = [];
        foreach ($policies as $branchId => $carriers) {
            if (!is_array($carriers)) continue;
            $normalized[(string) $branchId] = collect($carriers)->mapWithKeys(fn ($hours, $carrier) => [trim((string) $carrier) => (float) $hours])->all();
        }
        return $normalized;
    }

    private function normalizeCarrierMap(mixed $map): array
    {
        if (!is_array($map)) return [];
        return collect($map)->mapWithKeys(fn ($hours, $carrier) => [strtolower(trim((string) $carrier)) => (float) $hours])->all();
    }

    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function normalizeCalendar(array $calendar): array
    {
        $weekends = collect($calendar['weekend_days'] ?? [0, 6])->map(fn ($day): int => (int) $day)->filter(fn ($day): bool => $day >= 0 && $day <= 6)->unique()->values()->all();
        $holidays = collect($calendar['holidays'] ?? [])->map(fn ($day): string => (string) $day)->filter(fn ($day): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1)->unique()->values()->all();
        $start = $this->timeMinutes((string) ($calendar['shift_start'] ?? '00:00'));
        $end = $this->timeMinutes((string) ($calendar['shift_end'] ?? '24:00'));
        if ($end <= $start) throw new \InvalidArgumentException('SLA calendar shift end must be after shift start.');
        return ['weekend_days' => $weekends, 'holidays' => $holidays, 'shift_start_minutes' => $start, 'shift_end_minutes' => $end];
    }

    private function isWorkingDay(CarbonImmutable $day, array $calendar): bool
    {
        return !in_array($day->dayOfWeek, $calendar['weekend_days'], true) && !in_array($day->toDateString(), $calendar['holidays'], true);
    }

    private function timeMinutes(string $time): int
    {
        if ($time === '24:00') return 1440;
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new \InvalidArgumentException('SLA calendar shift times must use HH:MM format.');
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }

    private ErpSettingService $settings;

    public function __construct(ErpSettingService $settings)
    {
        $this->settings = $settings;
    }
}
