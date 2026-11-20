<?php

namespace App\Console\Commands;

use App\Models\EInvoiceSubmission;
use App\Services\AuditService;
use App\Services\EInvoiceService;
use Illuminate\Console\Command;

class RetryEInvoices extends Command
{
    protected $signature = 'erp:accounting:retry-e-invoices
        {--company= : Limit retries to a company ID}
        {--provider=http : Limit retries to one submit-capable provider}
        {--limit=50 : Maximum number of failed submissions to process}
        {--dry-run : Report eligible submissions without submitting them}';

    protected $description = 'Retry failed e-invoice submissions through their configured provider';

    public function handle(EInvoiceService $invoices, AuditService $audit): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $provider = strtolower(trim((string) $this->option('provider')));
        $query = EInvoiceSubmission::query()
            ->where('status', 'failed')
            ->when($provider !== '', fn ($builder) => $builder->where('provider', $provider))
            ->when($this->option('company'), fn ($builder, $company) => $builder->where('company_id', (int) $company))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        $eligible = 0;
        $retried = 0;
        $failed = 0;

        foreach ($query->get() as $submission) {
            $eligible++;
            if ($this->option('dry-run')) {
                $this->line("Would retry e-invoice {$submission->id} ({$submission->provider}).");
                continue;
            }

            try {
                $before = $submission->toArray();
                $updated = $invoices->submit($submission);
                $audit->record('e_invoice.retry_succeeded', $updated, $before, $updated->toArray());
                $retried++;
                $this->info("Retried e-invoice {$submission->id}: {$updated->status}.");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Failed e-invoice {$submission->id}: {$exception->getMessage()}");
            }
        }

        $this->line("E-invoice retry complete: {$eligible} eligible, {$retried} submitted, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
