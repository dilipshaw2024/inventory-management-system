<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\CostCenterBudget;
use App\Models\FiscalYear;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CostCenterController extends Controller
{
    public function index()
    {
        $costCenters = CostCenter::orderBy('code')->paginate(50);
        return view('admin.erp.cost_centers', compact('costCenters'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        if (CostCenter::where('code', $data['code'])->exists()) return back()->withErrors(['code' => 'This cost-center code already exists.'])->withInput();
        $center = CostCenter::create($data + ['is_active' => (bool) ($data['is_active'] ?? true)]);
        app(AuditService::class)->record('cost_center.created', $center, null, $center->toArray());
        return back()->with(['message' => 'Cost center created.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $center = CostCenter::findOrFail($id);
        $data = $request->validate(['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        if (CostCenter::where('code', $data['code'])->where('id', '<>', $id)->exists()) return back()->withErrors(['code' => 'This cost-center code already exists.']);
        $old = $center->toArray(); $center->update($data + ['is_active' => (bool) ($data['is_active'] ?? false)]);
        app(AuditService::class)->record('cost_center.updated', $center, $old, $center->fresh()->toArray());
        return back()->with(['message' => 'Cost center updated.', 'alert-type' => 'success']);
    }

    public function budgets()
    {
        $budgets = CostCenterBudget::with(['costCenter', 'fiscalYear'])->latest('period_start')->paginate(50);
        $costCenters = CostCenter::where('is_active', true)->orderBy('code')->get();
        $fiscalYears = FiscalYear::where('status', 'open')->when(auth()->user()?->company_id, fn ($query, $id) => $query->where('company_id', $id))->orderByDesc('starts_on')->get();
        return view('admin.erp.cost_center_budgets', compact('budgets', 'costCenters', 'fiscalYears'));
    }

    public function storeBudget(Request $request)
    {
        $data = $request->validate(['cost_center_id' => ['required', 'integer', 'exists:cost_centers,id'], 'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'budget_amount' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $center = CostCenter::findOrFail($data['cost_center_id']);
        if (($companyId = auth()->user()?->company_id) && $center->company_id && (int) $center->company_id !== (int) $companyId) abort(403);
        if (!empty($data['fiscal_year_id'])) {
            $fiscalYear = FiscalYear::whereKey($data['fiscal_year_id'])->when($companyId, fn ($query) => $query->where('company_id', $companyId))->firstOrFail();
            if ($data['period_start'] < $fiscalYear->starts_on->toDateString() || $data['period_end'] > $fiscalYear->ends_on->toDateString()) return back()->withErrors(['period_start' => 'Budget period must fall within the selected fiscal year.'])->withInput();
        }
        $budget = CostCenterBudget::create($data + ['company_id' => $companyId, 'created_by' => auth()->id(), 'currency_code' => strtoupper($data['currency_code'] ?? '') ?: null]);
        app(AuditService::class)->record('cost_center_budget.created', $budget, null, $budget->toArray());
        return back()->with(['message' => 'Cost-center budget created.', 'alert-type' => 'success']);
    }
}
