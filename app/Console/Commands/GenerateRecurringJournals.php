<?php

namespace App\Console\Commands;

use App\Services\RecurringJournalService;
use Illuminate\Console\Command;

class GenerateRecurringJournals extends Command
{
    protected $signature = 'erp:accounting:generate-recurring-journals {--company=} {--as-of=} {--limit=100}';
    protected $description = 'Generate due journals from active recurring journal templates.';

    public function handle(RecurringJournalService $service): int
    {
        $result = $service->generateDue($this->option('company') ? (int) $this->option('company') : null, $this->option('as-of'), (int) $this->option('limit'));
        $this->info('Generated '.$result['generated'].' recurring journal(s); skipped '.$result['skipped'].'.');
        foreach ($result['errors'] as $error) $this->error($error);
        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
