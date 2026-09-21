<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierCorrectiveAction;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierCorrectiveActionController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for supplier corrective actions.');
        $filters = $request->validate(['supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'status' => ['nullable', 'in:open,in_progress,resolved,closed'], 'severity' => ['nullable', 'in:low,medium,high,critical']]);
        $actions = SupplierCorrectiveAction::with(['supplier', 'owner', 'creator'])->where('company_id', $companyId)
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity))
            ->latest('updated_at')->paginate(30)->withQueryString();
        $summaryActions = SupplierCorrectiveAction::where('company_id', $companyId)->get();
        $summary = ['total' => $summaryActions->count(), 'open' => $summaryActions->where('status', 'open')->count(), 'in_progress' => $summaryActions->where('status', 'in_progress')->count(), 'overdue' => $summaryActions->whereIn('status', ['open', 'in_progress', 'resolved'])->filter(fn ($action) => $action->due_date !== null && $action->due_date->isPast())->count()];
        $suppliers = Supplier::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $users = User::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('backend.purchase.supplier_corrective_actions', compact('actions', 'suppliers', 'users', 'filters', 'summary'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'title' => ['required', 'string', 'max:200'], 'issue_type' => ['required', 'string', 'max:60'], 'severity' => ['required', 'in:low,medium,high,critical'], 'due_date' => ['nullable', 'date'], 'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'description' => ['nullable', 'string', 'max:10000']]);
        $action = SupplierCorrectiveAction::create($data + ['company_id' => $companyId, 'created_by' => auth()->id(), 'status' => 'open']);
        app(AuditService::class)->record('supplier_corrective_action.created', $action, null, $action->toArray());
        return back()->with(['message' => 'Supplier corrective action created.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $action = SupplierCorrectiveAction::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,resolved,closed'], 'severity' => ['sometimes', 'in:low,medium,high,critical'], 'due_date' => ['sometimes', 'nullable', 'date'], 'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'resolution' => ['sometimes', 'nullable', 'string', 'max:10000']]);
        if ($data['status'] === 'closed' && trim((string) ($data['resolution'] ?? $action->resolution)) === '') return back()->withErrors(['resolution' => 'A resolution is required before closing an action.'])->withInput();
        $beforeFields = array_values(array_unique(array_merge(array_keys($data), ['status', 'closed_at', 'closed_by'])));
        $before = $action->only($beforeFields);
        $updates = $data;
        if ($data['status'] === 'closed' && $action->status !== 'closed') $updates += ['closed_at' => now(), 'closed_by' => auth()->id()];
        if ($data['status'] !== 'closed' && $action->status === 'closed') $updates += ['closed_at' => null, 'closed_by' => null];
        $action->update($updates);
        $afterFields = array_values(array_unique(array_merge(array_keys($updates), ['status', 'closed_at', 'closed_by'])));
        app(AuditService::class)->record('supplier_corrective_action.updated', $action, $before, $action->fresh()->only($afterFields));
        return back()->with(['message' => 'Supplier corrective action updated.', 'alert-type' => 'success']);
    }
}
