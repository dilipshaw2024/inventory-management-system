<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductImportJob;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImportJobIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['status' => ['nullable', 'in:pending,processing,completed,failed'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $jobs = ProductImportJob::withoutGlobalScopes()->where('company_id', $companyId)->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest('id')->paginate($data['per_page'] ?? 50);
        return response()->json($jobs);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for product imports.');
        $externalReference = trim((string) $request->input('external_reference', '')) ?: null;
        if ($externalReference) {
            $existing = ProductImportJob::withoutGlobalScopes()->where('company_id', $companyId)->where('external_reference', $externalReference)->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:51200'], 'dry_run' => ['nullable', 'boolean'], 'max_attempts' => ['nullable', 'integer', 'min:1', 'max:9'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);
        $file = $request->file('file'); $path = $file->store('product-imports/'.$companyId, 'local');
        $job = ProductImportJob::create(['company_id' => $companyId, 'created_by' => $request->user()?->id, 'external_reference' => $externalReference, 'original_name' => $file->getClientOriginalName(), 'stored_path' => $path, 'dry_run' => (bool) ($data['dry_run'] ?? false), 'max_attempts' => (int) ($data['max_attempts'] ?? 3), 'status' => 'pending']);
        app(AuditService::class)->record('product_import_job.created', $job, null, $job->toArray());
        return response()->json(['data' => $job, 'status' => 'queued'], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $job = ProductImportJob::withoutGlobalScopes()->where('company_id', $request->user()?->company_id)->findOrFail($id);
        return response()->json(['data' => $job]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $job = DB::transaction(function () use ($request, $id): ProductImportJob {
            $job = ProductImportJob::withoutGlobalScopes()->where('company_id', $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'failed') abort(422, 'Only failed product import jobs can be retried.');
            if ((int) $job->attempts >= (int) $job->max_attempts) abort(422, 'The job has exhausted its retry limit; submit a new job or increase max_attempts before processing.');
            $job->update(['status' => 'pending', 'errors' => null, 'completed_at' => null, 'next_attempt_at' => now()]);
            return $job;
        });
        app(AuditService::class)->record('product_import_job.retry_requested', $job, null, ['job_id' => $job->id, 'attempts' => $job->attempts]);
        return response()->json(['data' => $job->fresh(), 'status' => 'queued']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $job = DB::transaction(function () use ($request, $id): ProductImportJob {
            $job = ProductImportJob::withoutGlobalScopes()->where('company_id', $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'pending') abort(422, 'Only pending product import jobs can be cancelled.');
            $job->update(['status' => 'cancelled', 'completed_at' => now(), 'next_attempt_at' => null]);
            return $job;
        });
        if ($job->stored_path && Storage::disk('local')->exists($job->stored_path)) Storage::disk('local')->delete($job->stored_path);
        app(AuditService::class)->record('product_import_job.cancelled', $job, null, ['job_id' => $job->id]);
        return response()->json(['data' => $job->fresh(), 'status' => 'cancelled']);
    }
}
