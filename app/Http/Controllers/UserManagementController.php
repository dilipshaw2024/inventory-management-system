<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()?->company_id;
        $users = User::when($companyId, fn ($query) => $query->where('company_id', $companyId))->with(['company', 'branch', 'department', 'roles'])->orderBy('name')->paginate(50);
        $companies = Company::when($companyId, fn ($query) => $query->whereKey($companyId))->where('is_active', true)->orderBy('name')->get();
        $branches = Branch::when($companyId, fn ($query) => $query->where('company_id', $companyId))->where('is_active', true)->orderBy('name')->get();
        $departments = Department::when($companyId, fn ($query) => $query->where('company_id', $companyId))->where('is_active', true)->orderBy('name')->get();
        return view('admin.erp.users', compact('users', 'companies', 'branches', 'departments'));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['company_id' => ['nullable', 'exists:companies,id'], 'branch_id' => ['nullable', 'exists:branches,id'], 'department_id' => ['nullable', 'exists:departments,id'], 'is_active' => ['nullable', 'boolean']]);
        $companyId = auth()->user()?->company_id;
        if ($companyId && (($data['company_id'] ?? $companyId) != $companyId)) abort(403);
        if ($companyId && !empty($data['branch_id']) && !Branch::whereKey($data['branch_id'])->where('company_id', $companyId)->exists()) abort(403);
        if ($companyId && !empty($data['department_id']) && !Department::whereKey($data['department_id'])->where('company_id', $companyId)->exists()) abort(403);
        $user = User::when($companyId, fn ($query) => $query->where('company_id', $companyId))->findOrFail($id); $old = $user->only(['company_id', 'branch_id', 'department_id', 'is_active']);
        $user->update($data + ['is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('security.user.updated', $user, $old, $user->only(['company_id', 'branch_id', 'department_id', 'is_active']));
        return back()->with(['message' => 'User scope/status updated.', 'alert-type' => 'success']);
    }

    public function resetMfa(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $companyId = auth()->user()?->company_id;
        $user = User::when($companyId, fn ($query) => $query->where('company_id', $companyId))->findOrFail($id);
        $wasEnabled = (bool) $user->mfa_enabled;
        $user->update(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_recovery_codes' => null, 'mfa_enabled_at' => null]);
        $user->tokens()->delete();
        app(AuditService::class)->record('security.user.mfa_reset', $user, ['mfa_enabled' => $wasEnabled], ['mfa_enabled' => false, 'reason' => $data['reason'], 'tokens_revoked' => true]);
        return back()->with(['message' => 'User MFA was reset and API tokens were revoked.', 'alert-type' => 'success']);
    }
}
