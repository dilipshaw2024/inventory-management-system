<?php

namespace App\Console\Commands;

use App\Models\PurchaseRfq;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CloseOverduePurchaseRfqs extends Command
{
    protected $signature = 'erp:procurement:close-overdue-rfqs {--company= : Limit closure to a company ID}';
    protected $description = 'Close submitted purchase RFQs whose response due date has passed.';

    public function handle(): int
    {
        $query = PurchaseRfq::query()->where('status', 'submitted')->whereNotNull('response_due')->whereDate('response_due', '<', Carbon::today());
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));

        $closed = 0;
        foreach ($query->pluck('id') as $rfqId) {
            $changed = DB::transaction(function () use ($rfqId): bool {
                $rfq = PurchaseRfq::lockForUpdate()->find($rfqId);
                if (!$rfq || $rfq->status !== 'submitted' || !$rfq->response_due || !$rfq->response_due->isPast()) return false;
                $before = $rfq->only(['status']);
                $rfq->update(['status' => 'closed']);
                app(AuditService::class)->record('purchase_rfq.closed_overdue', $rfq, $before, ['status' => 'closed', 'automated' => true]);
                return true;
            });
            if ($changed) $closed++;
        }

        $this->info("Closed {$closed} overdue purchase RFQ(s).");
        return self::SUCCESS;
    }
}
