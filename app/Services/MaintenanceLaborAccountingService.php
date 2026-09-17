<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\JournalEntry;
use App\Models\MaintenanceOrder;

class MaintenanceLaborAccountingService
{
    public function post(MaintenanceOrder $order, ?string $date = null): ?JournalEntry
    {
        if ((float) ($order->labor_cost ?? 0) <= 0) return null;
        if ($order->labor_journal_entry_id) return JournalEntry::find($order->labor_journal_entry_id);
        $companyId = $order->company_id ?: auth()->user()?->company_id;
        $expense = $this->account('service_labor_expense', $companyId);
        $payable = $this->account('service_labor_payable', $companyId);
        if (!$expense || !$payable) return null;
        $amount = (float) $order->labor_cost;
        $journal = app(AccountingService::class)->post([
            'company_id' => $companyId, 'entry_no' => 'JE-SVC-'.strtoupper(bin2hex(random_bytes(5))),
            'date' => $date ?: optional($order->completed_at)->toDateString() ?: now()->toDateString(),
            'description' => 'Service labor for maintenance order '.$order->order_no,
        ], [
            ['account_id' => $expense, 'debit' => $amount, 'credit' => 0, 'description' => 'Maintenance labor expense'],
            ['account_id' => $payable, 'debit' => 0, 'credit' => $amount, 'description' => 'Maintenance labor payable'],
        ], $order);
        $order->update(['labor_journal_entry_id' => $journal->id]);
        return $journal;
    }

    private function account(string $key, ?int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
