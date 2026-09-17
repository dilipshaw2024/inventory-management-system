<?php

namespace App\Console\Commands;

use App\Models\UserSession;
use Illuminate\Console\Command;

class PruneUserSessions extends Command
{
    protected $signature = 'erp:sessions:prune {--days=30 : Remove inactive sessions older than this many days}';
    protected $description = 'Remove revoked and stale ERP session registry records';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = UserSession::whereNotNull('revoked_at')
            ->orWhere('last_activity', '<', now()->subDays($days))
            ->delete();
        $this->info("Pruned {$deleted} session record(s).");
        return self::SUCCESS;
    }
}
