<?php

namespace App\Services;

class ProductionCostService
{
    public function operationCost(iterable $operations, float $receiptQuantity, float $plannedQuantity): float
    {
        if ($receiptQuantity <= 0 || $plannedQuantity <= 0) return 0.0;
        $runCost = 0.0;
        foreach ($operations as $operation) {
            $workCenter = $operation->workCenter;
            if (!$workCenter) continue;
            $setupMinutes = $operation->actual_setup_minutes ?? $operation->routingOperation?->setup_minutes ?? 0;
            $runMinutes = $operation->actual_run_minutes ?? $operation->routingOperation?->run_minutes ?? 0;
            $runCost += (($setupMinutes + $runMinutes) / 60) * ((float) ($workCenter->labor_rate ?? 0) + (float) ($workCenter->machine_rate ?? 0));
        }
        return $runCost * min(1, $receiptQuantity / $plannedQuantity);
    }
}
