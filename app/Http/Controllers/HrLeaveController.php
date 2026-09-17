<?php

namespace App\Http\Controllers;

use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Services\AuditService;
use App\Services\HrLeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrLeaveController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()?->company_id;
        $requests = HrLeaveRequest::with(['employee', 'leaveType', 'approver'])->where('company_id', $companyId)->latest('starts_on')->paginate(50);
        $employees = HrEmployee::where('company_id', $companyId)->where('status', 'active')->orderBy('employee_no')->get();
        $types = HrLeaveType::where('company_id', $companyId)->orderBy('code')->get();
        return view('admin.erp.hr_leave', compact('requests', 'employees', 'types'));
    }

    public function storeType(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('hr_leave_types', 'code')->where(fn ($query) => $query->where('company_id', $companyId))], 'name' => ['required', 'string', 'max:150'], 'annual_entitlement' => ['required', 'numeric', 'min:0'], 'is_paid' => ['nullable', 'boolean']]);
        $type = HrLeaveType::create($data + ['company_id' => $companyId, 'is_paid' => (bool) ($data['is_paid'] ?? true), 'is_active' => true]);
        app(AuditService::class)->record('hr_leave_type.created', $type, null, $type->toArray());
        return back()->with(['message' => 'Leave type created.', 'alert-type' => 'success']);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')->where(fn ($query) => $query->where('company_id', $companyId))], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['nullable', 'string', 'max:2000']]);
        try {
            $leave = app(HrLeaveService::class)->create($companyId, $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['leave' => $exception->getMessage()])->withInput();
        }
        app(AuditService::class)->record('hr_leave_request.created', $leave, null, $leave->toArray());
        return back()->with(['message' => 'Leave request submitted.', 'alert-type' => 'success']);
    }

    public function approve(int $id) { return $this->decide($id, 'approved'); }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['decision_reason' => ['required', 'string', 'max:2000']]);
        return $this->decide($id, 'rejected', $data['decision_reason']);
    }

    private function decide(int $id, string $status, ?string $reason = null)
    {
        $leave = HrLeaveRequest::where('company_id', auth()->user()?->company_id)->findOrFail($id);
        if ($leave->status !== 'pending') return back()->withErrors(['leave' => 'Only pending leave requests can be decided.']);
        $old = $leave->toArray();
        $leave->update(['status' => $status, 'approved_by' => auth()->id(), 'approved_at' => now(), 'decision_reason' => $reason]);
        app(AuditService::class)->record('hr_leave_request.'.$status, $leave, $old, $leave->toArray());
        return back()->with(['message' => 'Leave request '.$status.'.', 'alert-type' => 'success']);
    }
}
