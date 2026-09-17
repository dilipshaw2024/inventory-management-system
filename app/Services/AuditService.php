<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ApprovalRejectionNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public function record(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): AuditLog {
        $companyId = $model?->getAttribute('company_id') ?: auth()->user()?->company_id;
        return DB::transaction(function () use ($action, $model, $oldValues, $newValues, $userId, $companyId): AuditLog {
        // Serialize the predecessor lookup for tenant chains. Without this
        // lock, concurrent requests can both choose the same previous hash.
        if ($companyId !== null) Company::whereKey($companyId)->lockForUpdate()->first();
        $audit = AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $model?->getMorphClass(),
            'auditable_id' => $model?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'user_agent' => app()->bound('request') ? request()->userAgent() : null,
        ]);
        $previousQuery = AuditLog::where('id', '<', $audit->id);
        $previousQuery = $companyId === null ? $previousQuery->whereNull('company_id') : $previousQuery->where('company_id', $companyId);
        $previousHash = $previousQuery->latest('id')->value('integrity_hash');
        $audit->forceFill(['previous_hash' => $previousHash, 'integrity_hash' => app(\App\Services\AuditIntegrityService::class)->hash($audit, $previousHash)])->saveQuietly();
        if ($model && ($oldValues !== null || $newValues !== null)) {
            $revisionQuery = \App\Models\DocumentRevision::where('document_type', $model->getMorphClass())->where('document_id', $model->getKey());
            $companyId !== null ? $revisionQuery->where('company_id', $companyId) : $revisionQuery->whereNull('company_id');
            $version = (int) ($revisionQuery->lockForUpdate()->orderByDesc('version')->value('version') ?? 0) + 1;
            \App\Models\DocumentRevision::create(['company_id' => $companyId, 'document_type' => $model->getMorphClass(), 'document_id' => $model->getKey(), 'version' => $version, 'old_values' => $oldValues, 'new_values' => $newValues, 'changed_by' => $userId ?? auth()->id(), 'audit_log_id' => $audit->id, 'changed_at' => now()]);
        }
        if ($model && is_array($newValues) && array_key_exists('status', $newValues) && (($oldValues['status'] ?? null) !== $newValues['status'])) {
            \App\Models\DocumentStatusHistory::create([
                'company_id' => $companyId,
                'document_type' => $model->getMorphClass(), 'document_id' => $model->getKey(),
                'from_status' => isset($oldValues['status']) ? (string) $oldValues['status'] : null,
                'to_status' => (string) $newValues['status'], 'action' => $action,
                'changed_by' => $userId ?? auth()->id(), 'metadata' => ['audit_id' => $audit->id], 'changed_at' => now(),
            ]);
        }
        if ($model && str_ends_with($action, '.rejected') && is_array($newValues)) {
            $creatorId = $model->getAttribute('created_by') ?: $model->getAttribute('user_id');
            $creator = $creatorId ? User::withoutGlobalScopes()->whereKey($creatorId)->where('is_active', true)->first() : null;
            if ($creator && (!$companyId || !$creator->company_id || (int) $creator->company_id === (int) $companyId)) {
                $creator->notify(new ApprovalRejectionNotification([
                    'document_type' => $model->getMorphClass(), 'document_id' => $model->getKey(),
                    'action' => $action, 'rejection_reason' => $newValues['rejection_reason'] ?? $newValues['reason'] ?? null,
                    'rejected_by' => $userId ?? auth()->id(),
                ]));
            }
        }
        try {
            app(\App\Services\WebhookService::class)->dispatch($companyId, $action, ['auditable_type' => $model?->getMorphClass(), 'auditable_id' => $model?->getKey(), 'old_values' => $oldValues, 'new_values' => $newValues, 'user_id' => $userId ?? auth()->id()]);
        } catch (\Throwable $exception) {
            report($exception);
        }
        return $audit;
        });
    }
}
