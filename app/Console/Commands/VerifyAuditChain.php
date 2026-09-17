<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditIntegrityService;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'erp:security:verify-audit-chain {--company=}';
    protected $description = 'Verify tamper-evident audit log hash chains';

    public function handle(AuditIntegrityService $integrity): int
    {
        $query = AuditLog::query()->orderBy('id');
        if ($this->option('company') !== null) $query->where('company_id', (int) $this->option('company'));
        $previousByCompany = [];
        $checked = 0; $invalid = 0;
        foreach ($query->cursor() as $audit) {
            if (!$audit->integrity_hash) continue;
            $key = $audit->company_id === null ? 'shared' : (string) $audit->company_id;
            $expected = $previousByCompany[$key] ?? null;
            if (!$integrity->valid($audit, $expected)) { $invalid++; $this->error('Invalid audit hash at ID '.$audit->id.'.'); }
            $previousByCompany[$key] = $audit->integrity_hash;
            $checked++;
        }
        $this->info('Checked '.$checked.' sealed audit events; invalid: '.$invalid.'.');
        return $invalid ? self::FAILURE : self::SUCCESS;
    }
}
