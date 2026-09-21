<?php

namespace App\Console\Commands;

use App\Models\ProductImportJob;
use App\Services\AuditService;
use App\Services\ProductImportService;
use App\Services\ProductSpreadsheetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessProductImportJobs extends Command
{
    protected $signature = 'erp:products:process-import-jobs {--limit=25 : Maximum pending jobs to process}';
    protected $description = 'Process pending tenant-scoped product import jobs';

    public function handle(ProductSpreadsheetService $spreadsheet, ProductImportService $imports): int
    {
        $limit = max(1, min(250, (int) $this->option('limit')));
        $ids = ProductImportJob::withoutGlobalScopes()->where('status', 'pending')->where(function ($query): void { $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()); })->orderBy('id')->limit($limit)->pluck('id'); $processed = 0;
        foreach ($ids as $id) {
            $job = DB::transaction(function () use ($id): ?ProductImportJob {
                $locked = ProductImportJob::withoutGlobalScopes()->lockForUpdate()->find($id);
                if (!$locked || $locked->status !== 'pending') return null;
                $locked->update(['status' => 'processing', 'attempts' => (int) $locked->attempts + 1, 'started_at' => now(), 'next_attempt_at' => null, 'errors' => null]);
                return $locked;
            });
            if (!$job) continue;
            try {
                $path = Storage::disk('local')->path($job->stored_path); $extension = strtolower(pathinfo($job->original_name, PATHINFO_EXTENSION));
                if (!is_file($path)) throw new \RuntimeException('The stored import file is missing.');
                $validated = $imports->validateRows($spreadsheet->read($path, $extension), $job->company_id);
                if ($validated['errors']) {
                    $job->update(['status' => 'failed', 'row_count' => count($validated['rows']), 'errors' => $validated['errors'], 'completed_at' => now()]);
                    app(AuditService::class)->record('product_import_job.failed', $job, null, ['errors' => $validated['errors'], 'job_id' => $job->id]);
                } else {
                    $count = $job->dry_run ? 0 : $imports->importRows($validated['rows'], $job->company_id, $job->created_by);
                    $job->update(['status' => 'completed', 'row_count' => count($validated['rows']), 'imported_count' => $count, 'completed_at' => now()]);
                    app(AuditService::class)->record('product_import_job.completed', $job, null, ['rows' => count($validated['rows']), 'imported_count' => $count, 'dry_run' => $job->dry_run]);
                }
                $processed++;
            } catch (\Throwable $exception) {
                $retry = (int) $job->attempts < (int) $job->max_attempts;
                $job->update(['status' => $retry ? 'pending' : 'failed', 'errors' => [$exception->getMessage()], 'completed_at' => $retry ? null : now(), 'next_attempt_at' => $retry ? now()->addMinutes(5) : null]);
                app(AuditService::class)->record($retry ? 'product_import_job.retry_scheduled' : 'product_import_job.failed', $job, null, ['errors' => [$exception->getMessage()], 'job_id' => $job->id, 'attempts' => $job->attempts, 'max_attempts' => $job->max_attempts]);
                $this->error("Job {$job->id} failed: ".$exception->getMessage());
            }
        }
        $this->info("Processed {$processed} product import job(s)."); return self::SUCCESS;
    }
}
