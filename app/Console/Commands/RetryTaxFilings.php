<?php

namespace App\Console\Commands;

use App\Models\TaxFiling;
use App\Services\AuditService;
use App\Services\TaxFilingService;
use Illuminate\Console\Command;

class RetryTaxFilings extends Command
{
    protected $signature = 'erp:accounting:retry-tax-filings
        {--company= : Limit retries to a company ID}
        {--limit=50 : Maximum number of failed draft filings to process}
        {--dry-run : Report eligible filings without submitting them}';

    protected $description = 'Retry draft tax filings that have a retained provider error';

    public function handle(TaxFilingService $filings, AuditService $audit): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $query = TaxFiling::query()
            ->where('status', 'draft')
            ->whereNotNull('provider_error')
            ->where('submission_provider', 'http')
            ->when($this->option('company'), fn ($builder, $company) => $builder->where('company_id', (int) $company))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        $eligible = 0;
        $retried = 0;
        $failed = 0;

        foreach ($query->get() as $filing) {
            $eligible++;
            if ($this->option('dry-run')) {
                $this->line("Would retry tax filing {$filing->id} ({$filing->filing_no}).");
                continue;
            }

            try {
                $before = $filing->toArray();
                $updated = $filings->submit($filing, null, $filing->submission_provider);
                $audit->record('tax_filing.retry_succeeded', $updated, $before, $updated->toArray());
                $retried++;
                $this->info("Retried tax filing {$filing->id}: {$updated->status}.");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Failed tax filing {$filing->id}: {$exception->getMessage()}");
            }
        }

        $this->line("Tax filing retry complete: {$eligible} eligible, {$retried} submitted, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
