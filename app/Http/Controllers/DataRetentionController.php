<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataRetentionArchive;
use App\Models\DataRetentionHold;
use App\Models\DataRetentionPolicy;
use App\Models\DocumentRevision;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DataRetentionController extends Controller
{
    public function index()
    {
        $companyId = $this->companyId();
        $policies = DataRetentionPolicy::where('company_id', $companyId)->latest()->get();
        $holds = DataRetentionHold::with('placer')->where('company_id', $companyId)->whereNull('released_at')->latest()->paginate(25);
        $archives = DataRetentionArchive::where('company_id', $companyId)->latest('archived_at')->paginate(25, ['*'], 'archives_page');
        return view('admin.erp.data_retention', compact('policies', 'holds', 'archives'));
    }

    public function storePolicy(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('data_retention_policies', 'name')->where(fn ($query) => $query->where('company_id', $companyId))], 'record_type' => ['required', 'in:audit_logs,document_revisions'], 'retention_days' => ['required', 'integer', 'min:1'], 'archive_enabled' => ['nullable', 'boolean'], 'purge_enabled' => ['nullable', 'boolean']]);
        $policy = DataRetentionPolicy::create($data + ['company_id' => $companyId, 'archive_enabled' => (bool) ($data['archive_enabled'] ?? true), 'purge_enabled' => (bool) ($data['purge_enabled'] ?? false), 'is_active' => true]);
        app(AuditService::class)->record('retention_policy.created', $policy, null, $policy->toArray());
        return back()->with(['message' => 'Retention policy saved.', 'alert-type' => 'success']);
    }

    public function storeHold(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['record_type' => ['required', 'in:audit_logs,document_revisions'], 'record_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        $model = $data['record_type'] === 'audit_logs' ? AuditLog::class : DocumentRevision::class;
        if (!$model::whereKey($data['record_id'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists()) return back()->withErrors(['record_id' => 'The selected record does not exist.'])->withInput();
        $hold = DataRetentionHold::create($data + ['company_id' => $companyId, 'placed_by' => auth()->id()]);
        app(AuditService::class)->record('retention_hold.placed', $hold, null, $hold->toArray());
        return back()->with(['message' => 'Legal hold placed.', 'alert-type' => 'success']);
    }

    public function preview(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['policy_id' => ['required', 'integer']]);
        $policy = DataRetentionPolicy::where('company_id', $companyId)->findOrFail($data['policy_id']);
        $model = $policy->record_type === 'audit_logs' ? AuditLog::class : DocumentRevision::class;
        $cutoff = now()->subDays($policy->retention_days);
        $query = $model::where('created_at', '<', $cutoff)->whereNotExists(function ($subquery) use ($policy): void { $subquery->selectRaw('1')->from('data_retention_holds')->whereColumn('data_retention_holds.record_id', $policy->record_type === 'audit_logs' ? 'audit_logs.id' : 'document_revisions.id')->where('data_retention_holds.record_type', $policy->record_type)->where(function ($scope) use ($policy): void { $scope->where('data_retention_holds.company_id', $policy->company_id)->orWhereNull('data_retention_holds.company_id'); })->whereNull('released_at'); });
        return back()->with(['message' => "Retention preview: {$query->count()} {$policy->record_type} records are eligible after {$cutoff->toDateString()}. No records were changed.", 'alert-type' => 'info']);
    }

    public function releaseHold(Request $request, int $id)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['release_reason' => ['required', 'string', 'max:500']]);
        $hold = DB::transaction(function () use ($id, $data, $companyId): DataRetentionHold {
            $hold = DataRetentionHold::where('company_id', $companyId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($hold->released_at !== null) return $hold;
            $hold->forceFill([
                'released_at' => now(),
                'released_by' => auth()->id(),
                'release_reason' => $data['release_reason'],
            ])->save();
            return $hold;
        });
        if ($hold->wasChanged()) {
            app(AuditService::class)->record('retention_hold.released', $hold, ['released_at' => null], $hold->only(['released_at', 'released_by', 'release_reason']));
        }
        return back()->with(['message' => 'Legal hold released.', 'alert-type' => 'success']);
    }

    public function exportArchives()
    {
        $archives = DataRetentionArchive::where('company_id', $this->companyId())->orderBy('id')->cursor();
        return response()->streamDownload(function () use ($archives): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['id', 'record_type', 'record_id', 'archived_at', 'payload_hash', 'payload']);
            foreach ($archives as $archive) {
                fputcsv($handle, [$archive->id, $archive->record_type, $archive->record_id, optional($archive->archived_at)->toIso8601String(), $archive->payload_hash, json_encode($archive->payload, JSON_UNESCAPED_SLASHES)]);
            }
            fclose($handle);
        }, 'retention-archives-'.now()->format('YmdHis').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 403, 'A company is required for retention administration.');
        return $companyId;
    }
}
