<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\RoleConflict;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SecurityRoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        $permissions = Permission::orderBy('module')->orderBy('name')->get();
        $users = User::when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->with('roles')->orderBy('name')->get();
        $conflicts = RoleConflict::with(['role', 'conflictingRole'])->latest()->get();
        return view('admin.erp.security_roles', compact('roles', 'permissions', 'users', 'conflicts'));
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:80', 'unique:roles,code'], 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'], 'permission_ids' => ['nullable', 'array'], 'permission_ids.*' => ['exists:permissions,id']]);
        $role = Role::create(collect($data)->except('permission_ids')->all() + ['is_active' => true]);
        $role->permissions()->sync($data['permission_ids'] ?? []);
        app(AuditService::class)->record('security.role.created', $role, null, $role->toArray());
        return back()->with(['message' => 'Role created.', 'alert-type' => 'success']);
    }

    public function assignUser(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'role_id' => ['required', 'exists:roles,id']]);
        $user = User::when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->findOrFail($data['user_id']); $role = Role::findOrFail($data['role_id']);
        try { app(\App\Services\SegregationOfDutiesService::class)->assertCanAssign($user, $role); } catch (\RuntimeException $exception) { return back()->withErrors(['role_id' => $exception->getMessage()])->withInput(); }
        $user->roles()->syncWithoutDetaching([$role->id]);
        app(AuditService::class)->record('security.user_role.assigned', $user, null, $data);
        return back()->with(['message' => 'Role assigned to user.', 'alert-type' => 'success']);
    }

    public function storeConflict(Request $request)
    {
        $data = $request->validate(['role_id' => ['required', 'exists:roles,id'], 'conflicting_role_id' => ['required', 'exists:roles,id', 'different:role_id'], 'reason' => ['nullable', 'string', 'max:500']]);
        $pair = [min((int) $data['role_id'], (int) $data['conflicting_role_id']), max((int) $data['role_id'], (int) $data['conflicting_role_id'])];
        if (RoleConflict::where(['role_id' => $pair[0], 'conflicting_role_id' => $pair[1]])->orWhere(fn ($query) => $query->where('role_id', $pair[1])->where('conflicting_role_id', $pair[0]))->exists()) return back()->withErrors(['conflicting_role_id' => 'This role conflict already exists.'])->withInput();
        $conflict = RoleConflict::create(['company_id' => auth()->user()?->company_id, 'role_id' => $pair[0], 'conflicting_role_id' => $pair[1], 'reason' => $data['reason'] ?? null, 'is_active' => true, 'created_by' => auth()->id()]);
        app(AuditService::class)->record('security.role_conflict.created', $conflict, null, $conflict->toArray());
        return back()->with(['message' => 'Role conflict rule created.', 'alert-type' => 'success']);
    }
}
