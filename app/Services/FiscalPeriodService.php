<?php

namespace App\Services;

use App\Models\FiscalYear;
use Carbon\Carbon;

class FiscalPeriodService
{
    public function assertOpen(?int $companyId, string $date, string $message = 'No open fiscal period exists for this transaction date.'): void
    {
        if (!$companyId) return;
        $configured = FiscalYear::where('company_id', $companyId)->exists();
        if ($configured && !FiscalYear::where('company_id', $companyId)->where('status', 'open')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) throw new \RuntimeException($message);
    }

    public function assertOpenForReference(?int $companyId, ?\Illuminate\Database\Eloquent\Model $reference = null, ?string $date = null): void
    {
        $value = $date ?: ($reference?->getAttribute('date') ?: $reference?->getAttribute('invoice_date') ?: $reference?->getAttribute('receipt_date') ?: $reference?->getAttribute('delivery_date') ?: $reference?->getAttribute('order_date') ?: $reference?->getAttribute('payment_date') ?: now()->toDateString());
        $this->assertOpen($companyId, Carbon::parse($value)->toDateString());
    }
}
