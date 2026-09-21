<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalOverride;
use App\Models\ApprovalEscalation;
use App\Models\RoleConflict;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionPurgeRequest;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecurityIntegrationController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['nullable', 'string', 'max:150'], 'user_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for audit synchronization.');
        $logs = AuditLog::with('user')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%'))
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($logs, $request, 'security.audit-logs', (int) ($data['per_page'] ?? 50));
    }

    public function users(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $users = User::query()->select(['id', 'name', 'username', 'email', 'company_id', 'branch_id', 'department_id', 'is_active', 'email_verified_at', 'created_at', 'updated_at'])
            ->with(['roles:id,name,code,is_active'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($users, $request, 'security.users', (int) ($data['per_page'] ?? 50));
    }

    public function roles(Request $request): JsonResponse
    {
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $roles = Role::with(['permissions:id,name,code,module'])
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($roles, $request, 'security.roles', (int) ($data['per_page'] ?? 50));
    }

    public function permissions(Request $request): JsonResponse
    {
        $data = $request->validate(['module' => ['nullable', 'string', 'max:80'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $permissions = Permission::query()
            ->when($data['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($permissions, $request, 'security.permissions', (int) ($data['per_page'] ?? 50));
    }

    public function approvalEscalations(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval escalations.');
        $data = $request->validate(['status' => ['nullable', 'in:pending,acknowledged,superseded'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $escalations = ApprovalEscalation::with('user:id,name,email')->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($escalations, $request, 'security.approval-escalations', (int) ($data['per_page'] ?? 50));
    }

    public function acknowledgeApprovalEscalation(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval escalations.');
        $data = $request->validate(['acknowledgment_note' => ['nullable', 'string', 'max:2000']]);
        $escalation = ApprovalEscalation::where('company_id', $companyId)->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
        if ($escalation->status === 'acknowledged') return response()->json(['data' => $escalation, 'status' => 'already_acknowledged']);
        if ($escalation->status === 'superseded') return response()->json(['data' => $escalation, 'status' => 'already_superseded']);
        $before = $escalation->only(['status', 'acknowledged_at', 'acknowledgment_note']);
        $escalation->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledgment_note' => $data['acknowledgment_note'] ?? null]);
        app(AuditService::class)->record('approval_escalation.acknowledged', $escalation, $before, $escalation->fresh()->only(['status', 'acknowledged_at', 'acknowledgment_note']));
        return response()->json(['data' => $escalation->fresh('user'), 'status' => 'acknowledged']);
    }

    public function roleConflicts(Request $request): JsonResponse
    {
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $conflicts = RoleConflict::with(['role:id,name,code', 'conflictingRole:id,name,code'])
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($conflicts, $request, 'security.role-conflicts', (int) ($data['per_page'] ?? 50));
    }

    public function storeRoleConflict(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for role-conflict administration.');
        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'conflicting_role_id' => ['required', 'integer', Rule::exists('roles', 'id'), 'different:role_id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $pair = [min((int) $data['role_id'], (int) $data['conflicting_role_id']), max((int) $data['role_id'], (int) $data['conflicting_role_id'])];
        if (RoleConflict::where('role_id', $pair[0])->where('conflicting_role_id', $pair[1])->where('is_active', true)->exists()) return response()->json(['message' => 'This role conflict already exists.'], 422);
        $conflict = RoleConflict::create(['company_id' => $companyId, 'role_id' => $pair[0], 'conflicting_role_id' => $pair[1], 'reason' => $data['reason'] ?? null, 'is_active' => true, 'created_by' => $request->user()->id]);
        app(AuditService::class)->record('security.role_conflict.created', $conflict, null, $conflict->toArray());
        return response()->json(['data' => $conflict->load(['role:id,name,code', 'conflictingRole:id,name,code']), 'status' => 'created'], 201);
    }

    public function deactivateRoleConflict(Request $request, int $id): JsonResponse
    {
        $conflict = RoleConflict::findOrFail($id);
        if (!$conflict->is_active) return response()->json(['data' => $conflict, 'status' => 'already_inactive']);
        $before = $conflict->toArray();
        $conflict->update(['is_active' => false]);
        app(AuditService::class)->record('security.role_conflict.deactivated', $conflict, $before, $conflict->fresh()->toArray());
        return response()->json(['data' => $conflict->fresh(), 'status' => 'deactivated']);
    }

    public function approvalDelegations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'delegator_id' => ['nullable', 'integer'],
            'delegate_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval-delegation synchronization.');
        $delegations = ApprovalDelegation::with(['delegator:id,name,email', 'delegate:id,name,email'])
            ->where('company_id', $companyId)
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['delegator_id'] ?? null, fn ($query, $id) => $query->where('delegator_id', $id))
            ->when($data['delegate_id'] ?? null, fn ($query, $id) => $query->where('delegate_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($delegations, $request, 'security.approval-delegations', (int) ($data['per_page'] ?? 50));
    }

    public function storeApprovalDelegation(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval delegation.');
        $ownedUser = Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId));
        $data = $request->validate([
            'delegator_id' => ['required', 'integer', $ownedUser],
            'delegate_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId)), 'different:delegator_id'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $data['document_types'] = array_values(array_filter($data['document_types'] ?? [], fn ($type): bool => trim((string) $type) !== '')) ?: null;
        $overlap = ApprovalDelegation::where('company_id', $companyId)
            ->where('delegator_id', $data['delegator_id'])->where('delegate_id', $data['delegate_id'])
            ->where('is_active', true)->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
        if ($overlap) return response()->json(['message' => 'An active delegation for this delegator and delegate already overlaps that period.'], 422);

        $delegation = ApprovalDelegation::create($data + ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('approval_delegation.created', $delegation, null, $delegation->toArray());
        return response()->json(['data' => $delegation->load(['delegator:id,name,email', 'delegate:id,name,email'])], 201);
    }

    public function deactivateApprovalDelegation(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval delegation.');
        $delegation = ApprovalDelegation::where('company_id', $companyId)->findOrFail($id);
        if (!$delegation->is_active) return response()->json(['data' => $delegation, 'status' => 'already_inactive']);
        $delegation->update(['is_active' => false]);
        app(AuditService::class)->record('approval_delegation.deactivated', $delegation, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $delegation->fresh(), 'status' => 'deactivated']);
    }

    public function approvalPolicies(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval-policy synchronization.');
        $policies = ApprovalPolicy::with(['company', 'branch', 'category'])
            ->where('company_id', $companyId)
            ->when($data['document_type'] ?? null, fn ($query, $type) => $query->where('document_type', $type))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($policies, $request, 'security.approval-policies', (int) ($data['per_page'] ?? 50));
    }

    public function simulateSegregationOfDuties(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for segregation-of-duties simulation.');

        $ownedUser = Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId));
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'creator_id' => ['required', 'integer', $ownedUser],
            'approver_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);

        $creator = User::with('roles:id,name,code')->where('company_id', $companyId)->findOrFail($data['creator_id']);
        $approver = User::with(['roles:id,name,code', 'roles.permissions:id,code'])->where('company_id', $companyId)->findOrFail($data['approver_id']);
        $makerCheckerConflict = $creator->is($approver);
        $roleIds = $approver->roles->modelKeys();
        $roleConflicts = RoleConflict::with(['role:id,name,code', 'conflictingRole:id,name,code'])
            ->where('is_active', true)
            ->whereIn('role_id', $roleIds)->whereIn('conflicting_role_id', $roleIds)->get()
            ->map(fn (RoleConflict $conflict): array => [
                'role' => $conflict->role->only(['id', 'name', 'code']),
                'conflicting_role' => $conflict->conflictingRole->only(['id', 'name', 'code']),
                'reason' => $conflict->reason,
            ])->values();

        $amount = array_key_exists('amount', $data) ? (float) $data['amount'] : null;
        $policies = ApprovalPolicy::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('document_type', $data['document_type'])->where('is_active', true)
            ->when($amount !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
                ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount)))
            ->when($amount === null, fn ($query) => $query->whereNull('min_amount')->whereNull('max_amount'))
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $data['branch_id'] ?? null))
            ->where(fn ($query) => $query->whereNull('category_id')->orWhere('category_id', $data['category_id'] ?? null))
            ->orderBy('approval_step')->orderBy('id')->get();

        $steps = $policies->map(function (ApprovalPolicy $policy) use ($approver, $data, $companyId): array {
            $step = [
                'approval_step' => $policy->approval_step,
            'required_permission' => $policy->required_permission,
            'approver_has_direct_permission' => $approver->hasPermission($policy->required_permission),
            'approver_has_delegated_permission' => $this->hasDelegatedApprovalPermission($approver, $policy->required_permission, $data['document_type'], $companyId),
            ];
            $step['approver_has_permission'] = $step['approver_has_direct_permission'] || $step['approver_has_delegated_permission'];
            return $step;
        })->values();
        $missingPermissions = $steps->where('approver_has_permission', false)->pluck('required_permission')->unique()->values();

        return response()->json(['data' => [
            'allowed' => !$makerCheckerConflict && $roleConflicts->isEmpty() && $missingPermissions->isEmpty(),
            'maker_checker_conflict' => $makerCheckerConflict,
            'role_conflicts' => $roleConflicts,
            'approval_required' => $steps->isNotEmpty(),
            'approval_steps' => $steps,
            'missing_permissions' => $missingPermissions,
        ]]);
    }

    public function sodExceptions(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for SOD exception analytics.');
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'document_type' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ApprovalOverride::query()->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($scope, $status) => $scope->where('status', $status))
            ->when($data['document_type'] ?? null, fn ($scope, $type) => $scope->where('document_type', $type))
            ->when($data['from'] ?? null, fn ($scope, $date) => $scope->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($scope, $date) => $scope->whereDate('created_at', '<=', $date));

        $summary = [
            'total_requests' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
            'consumed' => (clone $query)->where('status', 'approved')->whereNotNull('consumed_at')->count(),
            'active_approved' => (clone $query)->where('status', 'approved')->whereNull('consumed_at')->count(),
            'by_document_type' => (clone $query)->selectRaw('document_type, COUNT(*) AS total')
                ->groupBy('document_type')->orderBy('document_type')->pluck('total', 'document_type'),
        ];
        $items = (clone $query)->with(['requester:id,name,email', 'approver:id,name,email', 'consumer:id,name,email'])
            ->latest('created_at')->latest('id')->limit((int) ($data['per_page'] ?? 50))->get();

        return response()->json(['data' => $items, 'summary' => $summary]);
    }

    private function hasDelegatedApprovalPermission(User $approver, string $permission, string $documentType, int $companyId): bool
    {
        return ApprovalDelegation::where('company_id', $companyId)->where('delegate_id', $approver->id)
            ->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now())
            ->get()->contains(function (ApprovalDelegation $delegation) use ($permission, $documentType, $companyId): bool {
                $documentTypes = $delegation->document_types ?: [];
                if ($documentTypes && !in_array('*', $documentTypes, true) && !in_array($documentType, $documentTypes, true)) return false;
                $delegator = User::whereKey($delegation->delegator_id)->where('is_active', true)
                    ->where('company_id', $companyId)->first();
                return $delegator?->hasPermission($permission) === true;
            });
    }

    public function storeApprovalPolicy(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval policy.');
        $data = $this->validateApprovalPolicy($request, $companyId);
        if (ApprovalPolicy::conflictsWith($data + ['company_id' => $companyId])) return response()->json(['message' => 'This active approval policy overlaps an existing policy for the same step and scope.'], 422);
        $policy = ApprovalPolicy::create($data + ['company_id' => $companyId, 'is_active' => (bool) ($data['is_active'] ?? true)]);
        app(AuditService::class)->record('approval_policy.created', $policy, null, $policy->toArray());
        return response()->json(['data' => $policy->load(['company', 'branch', 'category'])], 201);
    }

    public function updateApprovalPolicy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval policy.');
        $policy = ApprovalPolicy::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validateApprovalPolicy($request, $companyId);
        if (ApprovalPolicy::conflictsWith($data + ['company_id' => $companyId], $id)) return response()->json(['message' => 'This active approval policy overlaps an existing policy for the same step and scope.'], 422);
        $before = $policy->toArray();
        $policy->update($data + ['is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('approval_policy.updated', $policy, $before, $policy->fresh()->toArray());
        return response()->json(['data' => $policy->fresh()->load(['company', 'branch', 'category'])]);
    }

    public function deactivateApprovalPolicy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval policy.');
        $policy = ApprovalPolicy::where('company_id', $companyId)->findOrFail($id);
        if (!$policy->is_active) return response()->json(['data' => $policy, 'status' => 'already_inactive']);
        $policy->update(['is_active' => false]);
        app(AuditService::class)->record('approval_policy.deactivated', $policy, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $policy->fresh(), 'status' => 'deactivated']);
    }

    public function approvalOverrides(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval overrides.');
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'document_type' => ['nullable', 'string', 'max:150'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $overrides = ApprovalOverride::with(['requester:id,name,email', 'approver:id,name,email', 'consumer:id,name,email'])
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['document_type'] ?? null, fn ($query, $type) => $query->where('document_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($overrides, $request, 'security.approval-overrides', (int) ($data['per_page'] ?? 50));
    }

    public function storeApprovalOverride(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval overrides.');
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:150', Rule::exists('approval_policies', 'document_type')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'document_id' => ['required', 'integer', 'min:1'],
            'approval_step' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $policyExists = ApprovalPolicy::where('company_id', $companyId)->where('document_type', $data['document_type'])
            ->where('approval_step', $data['approval_step'])->where('is_active', true)->exists();
        if (!$policyExists) return response()->json(['message' => 'No active approval policy exists for this document and step.'], 422);
        $document = $this->ownedApprovalDocument($data['document_type'], (int) $data['document_id'], $companyId);
        $override = ApprovalOverride::create($data + ['company_id' => $companyId, 'requested_by' => $request->user()->id, 'status' => 'pending']);
        app(AuditService::class)->record('approval_override.requested', $override, null, $override->toArray());
        return response()->json(['data' => $override->load('requester:id,name,email'), 'document' => ['type' => $document->getMorphClass(), 'id' => $document->getKey()], 'status' => 'pending'], 201);
    }

    public function decideApprovalOverride(Request $request, int $id, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for approval overrides.');
        $data = $request->validate(['decision_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $override = ApprovalOverride::where('company_id', $companyId)->with('requester')->findOrFail($id);
        if ($override->status !== 'pending') return response()->json(['data' => $override, 'status' => 'already_decided'], 200);
        if ((int) $override->requested_by === (int) $request->user()->id) return response()->json(['message' => 'The override requester cannot decide the same override.'], 422);
        $before = $override->toArray();
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $override->update(['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['decision_reason']]);
        app(AuditService::class)->record('approval_override.'.$status, $override, $before, $override->fresh()->toArray());
        return response()->json(['data' => $override->fresh()->load(['requester:id,name,email', 'approver:id,name,email']), 'status' => $status]);
    }

    public function retentionPurgeRequests(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for retention purge requests.');
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,consumed'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $requests = DataRetentionPurgeRequest::with(['policy:id,name,record_type', 'requester:id,name,email', 'approver:id,name,email'])
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($requests, $request, 'security.retention-purge-requests', (int) ($data['per_page'] ?? 50));
    }

    public function storeRetentionPurgeRequest(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for retention purge requests.');
        $data = $request->validate(['policy_id' => ['required', 'integer', Rule::exists('data_retention_policies', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->where('purge_enabled', true))], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $policy = DataRetentionPolicy::where('company_id', $companyId)->whereKey($data['policy_id'])->where('is_active', true)->where('purge_enabled', true)->firstOrFail();
        if (DataRetentionPurgeRequest::where('company_id', $companyId)->where('policy_id', $policy->id)->where('status', 'pending')->exists()) return response()->json(['message' => 'A purge request for this policy is already pending.'], 422);
        $purge = DataRetentionPurgeRequest::create([
            'company_id' => $companyId, 'policy_id' => $policy->id, 'cutoff_at' => now()->subDays($policy->retention_days),
            'status' => 'pending', 'reason' => $data['reason'], 'requested_by' => $request->user()->id,
        ]);
        app(AuditService::class)->record('retention_purge.requested', $purge, null, $purge->toArray());
        return response()->json(['data' => $purge->load(['policy:id,name,record_type', 'requester:id,name,email']), 'status' => 'pending'], 201);
    }

    public function decideRetentionPurgeRequest(Request $request, int $id, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for retention purge requests.');
        $data = $request->validate(['decision_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $purge = DataRetentionPurgeRequest::where('company_id', $companyId)->findOrFail($id);
        if ($purge->status !== 'pending') return response()->json(['data' => $purge, 'status' => 'already_decided']);
        if ((int) $purge->requested_by === (int) $request->user()->id) return response()->json(['message' => 'The requester cannot decide the same purge request.'], 422);
        $before = $purge->toArray();
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $purge->update(['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['decision_reason']]);
        app(AuditService::class)->record('retention_purge.'.$status, $purge, $before, $purge->fresh()->toArray());
        return response()->json(['data' => $purge->fresh()->load(['policy:id,name,record_type', 'requester:id,name,email', 'approver:id,name,email']), 'status' => $status]);
    }

    private function ownedApprovalDocument(string $documentType, int $documentId, int $companyId): \Illuminate\Database\Eloquent\Model
    {
        abort_unless(class_exists($documentType) && is_subclass_of($documentType, \Illuminate\Database\Eloquent\Model::class), 422, 'The approval document type is not a supported model.');
        return $documentType::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($documentId);
    }

    private function validateApprovalPolicy(Request $request, int $companyId): array
    {
        return $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'approval_step' => ['required', 'integer', 'min:1'],
            'escalation_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'escalation_permissions' => ['nullable', 'array', 'max:10'],
            'escalation_permissions.*' => ['string', 'max:100', Rule::exists('permissions', 'code')],
            'max_escalation_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'required_permission' => ['required', Rule::exists('permissions', 'code')],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
