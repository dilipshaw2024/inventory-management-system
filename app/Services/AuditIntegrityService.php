<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditIntegrityService
{
    public function hash(AuditLog $audit, ?string $previousHash): string
    {
        return hash('sha256', json_encode([
            'id' => $audit->id, 'company_id' => $audit->company_id, 'user_id' => $audit->user_id,
            'action' => $audit->action, 'auditable_type' => $audit->auditable_type, 'auditable_id' => $audit->auditable_id,
            'old_values' => $audit->old_values, 'new_values' => $audit->new_values, 'ip_address' => $audit->ip_address,
            'user_agent' => $audit->user_agent, 'created_at' => optional($audit->created_at)->toISOString(), 'previous_hash' => $previousHash,
        ], JSON_UNESCAPED_SLASHES));
    }

    public function valid(AuditLog $audit, ?string $expectedPreviousHash = null): bool
    {
        return $audit->integrity_hash !== null
            && $audit->previous_hash === $expectedPreviousHash
            && hash_equals($audit->integrity_hash, $this->hash($audit, $audit->previous_hash));
    }
}
