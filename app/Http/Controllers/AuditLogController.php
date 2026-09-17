<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\DocumentStatusHistory;
use App\Models\DocumentRevision;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $userRule = Rule::exists('users', 'id');
        if ($companyId) $userRule->where('company_id', $companyId);
        $data = $request->validate(['action' => ['nullable', 'string', 'max:150'], 'user_id' => ['nullable', 'integer', $userRule], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $logs = AuditLog::with('user')->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%'))->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))->latest()->paginate(50)->withQueryString();
        $users = User::when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get(['id', 'name']);
        return view('admin.erp.audit_logs', compact('logs', 'users'));
    }

    public function activity(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $userRule = Rule::exists('users', 'id');
        if ($companyId) $userRule->where('company_id', $companyId);
        $data = $request->validate(['action' => ['nullable', 'string', 'max:80'], 'user_id' => ['nullable', 'integer', $userRule], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $activities = UserActivityLog::with('user')->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%'))->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))->latest('created_at')->paginate(50)->withQueryString();
        $users = User::when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get(['id', 'name']);
        return view('admin.erp.user_activity', compact('activities', 'users'));
    }

    public function activityExport(Request $request)
    {
        $data = $request->validate(['action' => ['nullable', 'string', 'max:80'], 'user_id' => ['nullable', 'exists:users,id'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $activities = DB::table('user_activity_logs')->leftJoin('users', 'users.id', '=', 'user_activity_logs.user_id')->select('user_activity_logs.*', 'users.name as user_name')->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where(fn ($scope) => $scope->where('user_activity_logs.company_id', $companyId)->orWhereNull('user_activity_logs.company_id')))->when($data['action'] ?? null, fn ($query, $action) => $query->where('user_activity_logs.action', 'like', '%'.$action.'%'))->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_activity_logs.user_id', $userId))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('user_activity_logs.created_at', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('user_activity_logs.created_at', '<=', $date))->orderBy('user_activity_logs.id')->cursor();
        return response()->streamDownload(function () use ($activities): void { $handle = fopen('php://output', 'w'); fputcsv($handle, ['ID', 'Timestamp', 'User', 'Email', 'Action', 'Route', 'IP', 'User agent', 'Metadata']); foreach ($activities as $activity) fputcsv($handle, [$activity->id, $activity->created_at, $activity->user_name ?? 'Guest', $activity->email, $activity->action, $activity->route, $activity->ip_address, $activity->user_agent, $activity->metadata]); fclose($handle); }, 'user-activity-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function export(Request $request)
    {
        $data = $request->validate(['action' => ['nullable', 'string', 'max:150'], 'user_id' => ['nullable', 'exists:users,id'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name as user_name')->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where(fn ($scope) => $scope->where('audit_logs.company_id', $companyId)->orWhereNull('audit_logs.company_id')))->when($data['action'] ?? null, fn ($query, $action) => $query->where('audit_logs.action', 'like', '%'.$action.'%'))->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('audit_logs.user_id', $userId))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('audit_logs.created_at', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('audit_logs.created_at', '<=', $date))->orderBy('audit_logs.id')->cursor();
        return response()->streamDownload(function () use ($logs): void { $handle = fopen('php://output', 'w'); fputcsv($handle, ['ID', 'Timestamp', 'User', 'Action', 'Auditable type', 'Auditable ID', 'IP', 'Old values', 'New values', 'Previous hash', 'Integrity hash']); foreach ($logs as $log) fputcsv($handle, [$log->id, $log->created_at, $log->user_name ?? 'System', $log->action, $log->auditable_type, $log->auditable_id, $log->ip_address, $log->old_values, $log->new_values, $log->previous_hash, $log->integrity_hash]); fclose($handle); }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function statusHistory(Request $request)
    {
        $data = $request->validate(['document_type' => ['nullable', 'string', 'max:150'], 'document_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $histories = DocumentStatusHistory::with('user')->when($data['document_type'] ?? null, fn ($query, $type) => $query->where('document_type', 'like', '%'.$type.'%'))->when($data['document_id'] ?? null, fn ($query, $id) => $query->where('document_id', $id))->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('changed_at', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('changed_at', '<=', $date))->latest('changed_at')->paginate(50)->withQueryString();
        return view('admin.erp.status_history', compact('histories'));
    }

    public function revisions(Request $request)
    {
        $data = $request->validate(['document_type' => ['nullable', 'string', 'max:150'], 'document_id' => ['nullable', 'integer']]);
        $revisions = DocumentRevision::with('user')->when($data['document_type'] ?? null, fn ($query, $type) => $query->where('document_type', 'like', '%'.$type.'%'))->when($data['document_id'] ?? null, fn ($query, $id) => $query->where('document_id', $id))->latest('changed_at')->paginate(50)->withQueryString();
        return view('admin.erp.document_revisions', compact('revisions'));
    }

    public function revisionDiff(int $id)
    {
        $revision = DocumentRevision::findOrFail($id);
        $old = is_array($revision->old_values) ? $revision->old_values : [];
        $new = is_array($revision->new_values) ? $revision->new_values : [];
        $keys = collect(array_unique(array_merge(array_keys($old), array_keys($new))))->sort()->values();
        $changes = $keys->mapWithKeys(function (string $key) use ($old, $new): array {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            return $before === $after ? [] : [$key => ['from' => $before, 'to' => $after]];
        });

        return response()->json([
            'revision_id' => $revision->id,
            'document_type' => $revision->document_type,
            'document_id' => $revision->document_id,
            'version' => $revision->version,
            'changed_at' => $revision->changed_at?->toISOString(),
            'changed_by' => $revision->changed_by,
            'changes' => $changes,
        ]);
    }
}
