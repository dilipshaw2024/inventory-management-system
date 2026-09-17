<?php

namespace App\Console\Commands;

use App\Models\ServiceContract;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireServiceContracts extends Command
{
    protected $signature = 'erp:service:expire-contracts {--company= : Limit processing to a company ID}';
    protected $description = 'Expire active service contracts whose end date has passed.';

    public function handle(): int
    {
        $query = ServiceContract::query()->where('status', 'active')->whereDate('ends_on', '<', now()->toDateString());
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));
        $expired = 0;
        foreach ($query->pluck('id') as $contractId) {
            $changed = DB::transaction(function () use ($contractId): bool {
                $contract = ServiceContract::lockForUpdate()->find($contractId);
                if (!$contract || $contract->status !== 'active' || !$contract->ends_on || !$contract->ends_on->isPast()) return false;
                $contract->update(['status' => 'expired']);
                app(AuditService::class)->record('service_contract.expired', $contract, ['status' => 'active'], ['status' => 'expired', 'automated' => true]);
                return true;
            });
            if ($changed) $expired++;
        }
        $this->info("Expired {$expired} service contract(s).");
        return self::SUCCESS;
    }
}
