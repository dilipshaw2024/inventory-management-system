<?php

namespace App\Http\Controllers;

use App\Models\ApprovalPolicy;
use App\Models\Permission;
use App\Models\Branch;
use App\Models\Category;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalPolicyController extends Controller
{
    public function index()
    {
        $policies = ApprovalPolicy::orderBy('document_type')->orderBy('min_amount')->get();
        $permissions = Permission::orderBy('module')->orderBy('code')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        return view('admin.erp.approval_policies', compact('policies', 'permissions', 'branches', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'approval_step' => ['required', 'integer', 'min:1'],
            'escalation_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'escalation_permissions' => ['nullable', 'array', 'max:10'],
            'escalation_permissions.*' => ['string', 'max:100', Rule::exists('permissions', 'code')],
            'max_escalation_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))],
            'required_permission' => ['required', 'exists:permissions,code'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (ApprovalPolicy::conflictsWith($data)) return back()->withErrors(['document_type' => 'This active approval policy overlaps an existing policy for the same step and scope.'])->withInput();
        $policy = ApprovalPolicy::create($data + ['is_active' => (bool) ($data['is_active'] ?? true)]);
        app(AuditService::class)->record('approval_policy.created', $policy, null, $policy->toArray());
        return back()->with(['message' => 'Approval policy created.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $policy = ApprovalPolicy::findOrFail($id);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'approval_step' => ['required', 'integer', 'min:1'],
            'escalation_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'escalation_permissions' => ['nullable', 'array', 'max:10'],
            'escalation_permissions.*' => ['string', 'max:100', Rule::exists('permissions', 'code')],
            'max_escalation_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))],
            'required_permission' => ['required', 'exists:permissions,code'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (ApprovalPolicy::conflictsWith($data, $id)) return back()->withErrors(['document_type' => 'This active approval policy overlaps an existing policy for the same step and scope.'])->withInput();
        $old = $policy->toArray();
        $policy->update($data + ['is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('approval_policy.updated', $policy, $old, $policy->fresh()->toArray());
        return back()->with(['message' => 'Approval policy updated.', 'alert-type' => 'success']);
    }
}
