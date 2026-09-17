<?php

namespace App\Services;

class ProductionCapacityService
{
    public function plannedLoadHours(iterable $operations): float
    {
        $minutes = 0.0;
        foreach ($operations as $operation) {
            $routing = $operation->routingOperation ?? null;
            $minutes += (float) ($routing->setup_minutes ?? 0) + ((float) ($routing->run_minutes ?? 0) * (float) ($operation->planned_quantity ?? 0));
        }
        return $minutes / 60;
    }

    public function utilizationPercent(float $loadHours, float $capacityHours): ?float
    {
        return $capacityHours > 0 ? round(($loadHours / $capacityHours) * 100, 2) : null;
    }
}
