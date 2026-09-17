<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\ApprovalDelegation;
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

    private function validateApprovalPolicy(Request $request, int $companyId): array
    {
        return $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'approval_step' => ['required', 'integer', 'min:1'],
            'escalation_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'required_permission' => ['required', Rule::exists('permissions', 'code')],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
