<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DataRetentionArchive;
use App\Models\DataRetentionHold;
use App\Models\DataRetentionPolicy;
use App\Models\DocumentRevision;
use Illuminate\Console\Command;

class ProcessDataRetention extends Command
{
    protected $signature = 'erp:retention:process {--purge : Delete eligible source records only when their policy allows it}';
    protected $description = 'Archive eligible audit records while respecting active legal holds';

    public function handle(): int
    {
        $processed = 0; $purged = 0;
        foreach (DataRetentionPolicy::where('is_active', true)->get() as $policy) {
            $model = $policy->record_type === 'audit_logs' ? AuditLog::class : DocumentRevision::class;
            $dateColumn = $policy->record_type === 'audit_logs' ? 'created_at' : 'changed_at';
            $model::where($dateColumn, '<', now()->subDays($policy->retention_days))->when($policy->company_id, fn ($query, $companyId) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->orderBy('id')->chunkById(100, function ($records) use ($policy, $model, &$processed, &$purged): void {
                foreach ($records as $record) {
                    $held = DataRetentionHold::where('record_type', $policy->record_type)->where('record_id', $record->getKey())->where(function ($query) use ($policy): void { $query->where('company_id', $policy->company_id)->orWhereNull('company_id'); })->whereNull('released_at')->exists();
                    if ($held) continue;
                    if ($policy->archive_enabled) {
                        $payload = $record->toArray();
                        DataRetentionArchive::firstOrCreate(['company_id' => $policy->company_id, 'record_type' => $policy->record_type, 'record_id' => $record->getKey()], ['payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 'archived_at' => now()]);
                        $processed++;
                    }
                    if ($this->option('purge') && $policy->purge_enabled && $policy->archive_enabled) { $record->delete(); $purged++; }
                }
            });
        }
        $this->info("Archived {$processed} records; purged {$purged} source records.");
        return self::SUCCESS;
    }
}
