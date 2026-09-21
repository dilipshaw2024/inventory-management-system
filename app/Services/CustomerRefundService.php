<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\CustomerRefund;
use App\Models\InventoryReturn;
use App\Models\JournalEntry;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class CustomerRefundService
{
    public function approve(CustomerRefund $refund): CustomerRefund
    {
        return DB::transaction(function () use ($refund): CustomerRefund {
            $refund = CustomerRefund::lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'pending') throw new \RuntimeException('This refund has already been processed.');
            $return = InventoryReturn::with('lines')->lockForUpdate()->findOrFail($refund->inventory_return_id);
            if ($return->status !== 'approved' || $return->return_type !== 'sales') throw new \RuntimeException('Only approved sales returns can be refunded.');
            if ((int) $return->customer_id !== (int) $refund->customer_id) throw new \RuntimeException('Refund customer does not match the sales return.');
            $maximum = (float) $return->lines->sum(fn ($line) => (float) $line->quantity * (float) ($line->unit_price ?? $line->product?->sales_price ?? 0) * (1 + ($return->tax_exempt ? 0 : (float) ($line->tax_rate ?? 0)) / 100));
            $already = (float) CustomerRefund::where('inventory_return_id', $return->id)->where('status', 'approved')->where('id', '<>', $refund->id)->sum('amount');
            if ((float) $refund->amount > max(0, $maximum - $already) + 0.000001) throw new \RuntimeException('Refund exceeds the refundable sales-return value.');
            $companyId = $refund->company_id ?: auth()->user()?->company_id;
            $cash = AccountMapping::where('mapping_key', $refund->method === 'cash' ? 'cash' : 'bank')->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id') ?: AccountMapping::where('mapping_key', 'cash_bank')->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
            $receivable = AccountMapping::where('mapping_key', 'accounts_receivable')->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
            $baseAmount = (float) ($refund->base_amount ?: ((float) $refund->amount * (float) ($refund->exchange_rate ?: 1)));
            if ($cash && $receivable) app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => now()->toDateString(), 'description' => 'Customer refund '.$refund->refund_no], [['account_id' => $receivable, 'debit' => $baseAmount, 'credit' => 0], ['account_id' => $cash, 'debit' => 0, 'credit' => $baseAmount]], $refund);
            $refund->update(['base_amount' => $baseAmount]);
            $refund->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            return $refund->fresh();
        });
    }

    public function settle(CustomerRefund $refund, string $reference): CustomerRefund
    {
        return DB::transaction(function () use ($refund, $reference): CustomerRefund {
            $refund = CustomerRefund::lockForUpdate()->findOrFail($refund->id);
            if ($refund->settlement_status === 'settled') {
                if ($refund->settlement_reference === $reference) return $refund->fresh();
                throw new \RuntimeException('This refund has already been settled with a different reference.');
            }
            if ($refund->status !== 'approved') throw new \RuntimeException('Only approved customer refunds can be settled.');
            if (CustomerRefund::where('company_id', $refund->company_id)->where('settlement_reference', $reference)->where('id', '<>', $refund->id)->exists()) throw new \RuntimeException('The settlement reference is already used by another customer refund.');
            $before = $refund->only(['settlement_status', 'settlement_reference', 'settled_at', 'settled_by']);
            $refund->update(['settlement_status' => 'settled', 'settlement_reference' => $reference, 'settled_at' => now(), 'settled_by' => auth()->id()]);
            app(AuditService::class)->record('customer_refund.settled', $refund, $before, $refund->fresh()->only(['settlement_status', 'settlement_reference', 'settled_at', 'settled_by']));
            return $refund->fresh();
        });
    }
}
