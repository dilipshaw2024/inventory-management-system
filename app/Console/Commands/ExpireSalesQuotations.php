<?php

namespace App\Console\Commands;

use App\Models\SalesQuotation;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpireSalesQuotations extends Command
{
    protected $signature = 'erp:sales:expire-quotations {--company= : Limit expiration to a company ID}';
    protected $description = 'Mark submitted and approved sales quotations past their validity date as expired.';

    public function handle(): int
    {
        $query = SalesQuotation::query()->whereIn('status', ['submitted', 'approved'])->whereNotNull('valid_until')->whereDate('valid_until', '<', Carbon::today());
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));

        $expired = 0;
        foreach ($query->pluck('id') as $quotationId) {
            $changed = DB::transaction(function () use ($quotationId): bool {
                $quotation = SalesQuotation::lockForUpdate()->find($quotationId);
                if (!$quotation || !in_array($quotation->status, ['submitted', 'approved'], true) || !$quotation->valid_until || !$quotation->valid_until->isPast()) return false;
                $before = $quotation->only(['status']);
                $quotation->update(['status' => 'expired']);
                app(AuditService::class)->record('sales_quotation.expired', $quotation, $before, ['status' => 'expired', 'automated' => true]);
                return true;
            });
            if ($changed) $expired++;
        }

        $this->info("Expired {$expired} sales quotation(s).");
        return self::SUCCESS;
    }
}
