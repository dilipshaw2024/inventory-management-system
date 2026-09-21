<?php

namespace App\Services;

use App\Models\StockCount;

class StockCountVariancePolicyService
{
    public function threshold(?int $companyId = null): float
    {
        return max(0.0, (float) app(ErpSettingService::class)->get('stock_count_recount_variance_percent', 0, $companyId));
    }

    public function requiringRecount(StockCount $count): ?array
    {
        $threshold = $this->threshold((int) $count->company_id);
        if ($threshold <= 0 || $count->recount_required) return null;

        foreach ($count->lines as $line) {
            $system = (float) $line->system_quantity;
            $variance = abs((float) $line->counted_quantity - $system);
            $percent = $variance / max(abs($system), 1.0) * 100;
            if ($percent > $threshold + 0.000001) {
                return ['product_id' => (int) $line->product_id, 'variance_quantity' => $variance, 'variance_percent' => $percent, 'threshold_percent' => $threshold];
            }
        }

        return null;
    }
}
