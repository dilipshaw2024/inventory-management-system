<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DataRetentionArchive;
use App\Models\DataRetentionHold;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionPurgeRequest;
use App\Models\DocumentRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessDataRetention extends Command
{
    protected $signature = 'erp:retention:process {--purge : Delete eligible source records only with an approved purge request} {--purge-request= : Approved purge request ID to consume}';
    protected $description = 'Archive eligible audit records while respecting active legal holds';

    public function handle(): int
    {
        $processed = 0; $purged = 0;
        $purgeRequest = null;
        if ($this->option('purge') && !$this->option('purge-request')) {
            $this->error('Purge requires --purge-request=<approved request ID>. No source records were changed.');
            return self::FAILURE;
        }
        if (!$this->option('purge') && $this->option('purge-request')) {
            $this->error('--purge-request can only be used together with --purge.');
            return self::FAILURE;
        }
        if ($this->option('purge-request')) {
            $purgeRequest = DataRetentionPurgeRequest::with('policy')->whereKey((int) $this->option('purge-request'))->first();
            if (!$purgeRequest || $purgeRequest->status !== 'approved' || $purgeRequest->consumed_at) {
                $this->error('The purge request must exist, be approved, and not already be consumed.');
                return self::FAILURE;
            }
        }
        $policies = $purgeRequest ? collect([$purgeRequest->policy]) : DataRetentionPolicy::where('is_active', true)->get();
        foreach ($policies as $policy) {
            $model = match ($policy->record_type) {
                'audit_logs' => AuditLog::class,
                'document_revisions' => DocumentRevision::class,
                'document_attachments' => \App\Models\DocumentAttachment::class,
                default => null,
            };
            if (!$model) continue;
            $dateColumn = $policy->record_type === 'document_revisions' ? 'changed_at' : 'created_at';
            $cutoff = $purgeRequest?->cutoff_at ?: now()->subDays($policy->retention_days);
            $model::where($dateColumn, '<', $cutoff)->when($policy->company_id, fn ($query, $companyId) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->orderBy('id')->chunkById(100, function ($records) use ($policy, $model, $purgeRequest, &$processed, &$purged): void {
                foreach ($records as $record) {
                    $held = DataRetentionHold::where('record_type', $policy->record_type)->where('record_id', $record->getKey())->where(function ($query) use ($policy): void { $query->where('company_id', $policy->company_id)->orWhereNull('company_id'); })->whereNull('released_at')->exists();
                    if ($held) continue;
                    if ($policy->archive_enabled) {
                        $payload = $record->toArray();
                        DataRetentionArchive::firstOrCreate(['company_id' => $policy->company_id, 'record_type' => $policy->record_type, 'record_id' => $record->getKey()], ['payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 'archived_at' => now()]);
                        $processed++;
                    }
                    if ($purgeRequest && $policy->purge_enabled && $policy->archive_enabled) {
                        if ($policy->record_type === 'document_attachments' && $record->stored_path) Storage::disk('local')->delete($record->stored_path);
                        $record->delete(); $purged++;
                    }
                }
            });
        }
        if ($purgeRequest) $purgeRequest->update(['status' => 'consumed', 'consumed_at' => now()]);
        $this->info("Archived {$processed} records; purged {$purged} source records.");
        return self::SUCCESS;
    }
}
