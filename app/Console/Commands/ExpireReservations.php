<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\StockReservation;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireReservations extends Command
{
    protected $signature = 'erp:inventory:expire-reservations {--company= : Limit expiry to a company ID} {--before= : Expire reservations at or before this timestamp}';
    protected $description = 'Release open stock reservations whose configured expiry has passed.';

    public function handle(AuditService $audit): int
    {
        $before = $this->option('before') ? now()->parse($this->option('before')) : now();
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))->pluck('id');
        $expired = 0;
        foreach ($companies as $companyId) {
            StockReservation::where('company_id', $companyId)
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $before)
                ->orderBy('id')
                ->chunkById(100, function ($reservations) use ($audit, &$expired): void {
                    foreach ($reservations as $reservation) {
                        DB::transaction(function () use ($reservation, $audit, &$expired): void {
                            $locked = StockReservation::lockForUpdate()->find($reservation->id);
                            if (!$locked || $locked->status !== 'active' || !$locked->expires_at || $locked->expires_at->isFuture()) return;
                            $old = ['status' => $locked->status, 'released_quantity' => $locked->released_quantity, 'expires_at' => $locked->expires_at?->toISOString()];
                            $locked->update(['status' => 'released', 'released_quantity' => $locked->quantity]);
                            $audit->record('stock_reservation.expired', $locked, $old, ['status' => $locked->status, 'released_quantity' => $locked->released_quantity, 'expires_at' => $locked->expires_at?->toISOString()]);
                            $expired++;
                        });
                    }
                });
            $this->info('Company '.$companyId.': expired reservations processed.');
        }
        $this->info('Total reservations expired: '.$expired.'.');
        return self::SUCCESS;
    }
}
