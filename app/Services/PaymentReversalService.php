<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaymentReversalService
{
    public function reverse(Model $payment, string $reason): Model
    {
        return DB::transaction(function () use ($payment, $reason): Model {
            $class = $payment instanceof Payment ? Payment::class : SupplierPayment::class;
            $payment = $class::lockForUpdate()->findOrFail($payment->id);
            if ($payment->is_reversed) throw new \RuntimeException('This payment has already been reversed.');
            if (($payment instanceof SupplierPayment && $payment->status !== 'approved') || ($payment instanceof Payment && (($payment->approval_status ?? 'approved') !== 'approved' || (float) $payment->paid_amount <= 0))) throw new \RuntimeException('Only posted payments can be reversed.');
            if ($payment instanceof Payment && $payment->allocations()->whereNull('voided_at')->exists()) throw new \RuntimeException('Allocated customer payments must be unallocated before reversal.');
            if ($payment instanceof SupplierPayment && $payment->allocations()->whereNull('voided_at')->exists()) throw new \RuntimeException('Allocated supplier payments must be unallocated before reversal.');
            $journal = JournalEntry::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->where('status', 'posted')->latest('id')->first();
            if ($journal) app(AccountingService::class)->reverse($journal, $reason);
            $payment->update(['is_reversed' => true, 'reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_reason' => $reason]);
            return $payment;
        });
    }
}
