<?php

namespace App\Services;

class TaxCalculationService
{
    public function exclusive(float $netAmount, float $rate): float
    {
        return round($netAmount * ($rate / 100), 6);
    }

    public function inclusive(float $grossAmount, float $rate): array
    {
        $net = $rate > 0 ? $grossAmount / (1 + ($rate / 100)) : $grossAmount;
        return ['net' => round($net, 6), 'tax' => round($grossAmount - $net, 6)];
    }
}
