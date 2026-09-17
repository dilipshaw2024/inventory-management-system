<?php

namespace App\Services;

class SupplierPerformanceScoringService
{
    /**
     * Score suppliers on a 0-100 scale:
     * fulfillment 40%, on-time delivery 30%, quality 20%, price 10%.
     */
    public function score(array $metrics): float
    {
        $fulfillment = $this->bound((float) ($metrics['fill_rate'] ?? 0));
        $onTime = $this->bound((float) ($metrics['on_time_rate'] ?? 0));
        $quality = $metrics['quality_pass_rate'] === null ? 100.0 : $this->bound((float) $metrics['quality_pass_rate']);
        $priceVariance = max(0.0, (float) ($metrics['price_variance'] ?? 0));
        $price = $this->bound(100.0 - $priceVariance);

        return round(($fulfillment * 0.40) + ($onTime * 0.30) + ($quality * 0.20) + ($price * 0.10), 2);
    }

    private function bound(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
