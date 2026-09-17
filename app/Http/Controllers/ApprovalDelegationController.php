<?php

namespace App\Http\Controllers;

use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalDelegationController extends Controller
{
    public function index()
    {
        $delegations = ApprovalDelegation::with(['delegator:id,name', 'delegate:id,name'])->latest()->paginate(40);
        $users = User::where('company_id', auth()->user()?->company_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('admin.erp.approval_delegations', compact('delegations', 'users'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'Select a company before configuring approval delegation.');
        $ownedUser = fn () => Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId));
        $data = $request->validate(['delegator_id' => ['required', 'integer', $ownedUser()], 'delegate_id' => ['required', 'integer', $ownedUser(), 'different:delegator_id'], 'document_types' => ['nullable', 'array'], 'document_types.*' => ['nullable', 'string', 'max:150'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'reason' => ['nullable', 'string', 'max:500']]);
        $data['document_types'] = array_values(array_filter($data['document_types'] ?? [], fn ($type): bool => trim((string) $type) !== '')) ?: null;
        $overlap = ApprovalDelegation::where('delegator_id', $data['delegator_id'])->where('delegate_id', $data['delegate_id'])->where('is_active', true)->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
        if ($overlap) return back()->withErrors(['starts_at' => 'An active delegation for this delegator and delegate already overlaps that period.'])->withInput();
        $delegation = ApprovalDelegation::create($data + ['company_id' => $companyId, 'is_active' => true]);
        app(AuditService::class)->record('approval_delegation.created', $delegation, null, $delegation->toArray());
        return back()->with(['message' => 'Approval delegation created.', 'alert-type' => 'success']);
    }

    public function deactivate(int $id)
    {
        $delegation = ApprovalDelegation::findOrFail($id);
        if (!$delegation->is_active) return back()->with(['message' => 'Approval delegation is already inactive.', 'alert-type' => 'error']);
        $delegation->update(['is_active' => false]);
        app(AuditService::class)->record('approval_delegation.deactivated', $delegation, ['is_active' => true], ['is_active' => false]);
        return back()->with(['message' => 'Approval delegation deactivated.', 'alert-type' => 'success']);
    }
}
