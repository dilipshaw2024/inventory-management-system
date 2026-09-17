<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;

class CurrencyConversionService
{
    public function rate(string $fromCode, string $toCode, ?string $date = null): float
    {
        $fromCode = strtoupper($fromCode);
        $toCode = strtoupper($toCode);
        if ($fromCode === $toCode) return 1.0;
        $from = Currency::where('code', $fromCode)->where('is_active', true)->first();
        $to = Currency::where('code', $toCode)->where('is_active', true)->first();
        if (!$from || !$to) throw new \RuntimeException("Currency pair {$fromCode}/{$toCode} is not configured.");
        $asOf = $date ?: now()->toDateString();
        $direct = ExchangeRate::where('from_currency_id', $from->id)->where('to_currency_id', $to->id)->whereDate('effective_date', '<=', $asOf)->latest('effective_date')->first();
        if ($direct && (float) $direct->rate > 0) return (float) $direct->rate;
        $inverse = ExchangeRate::where('from_currency_id', $to->id)->where('to_currency_id', $from->id)->whereDate('effective_date', '<=', $asOf)->latest('effective_date')->first();
        if ($inverse && (float) $inverse->rate > 0) return 1 / (float) $inverse->rate;
        throw new \RuntimeException("No effective exchange rate is configured for {$fromCode}/{$toCode}.");
    }
}
