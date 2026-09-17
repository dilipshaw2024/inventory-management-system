@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
<div class="page-title-box"><h4>Approval policies</h4><p class="text-muted">Apply company-specific approval authority by amount, branch, product category, ordered step, and optional escalation SLA.</p></div>
<div class="card mb-3"><div class="card-body"><form method="POST" action="{{ route('erp.security.approval-policies.store') }}" class="row g-2 align-items-end">@csrf
<div class="col-md-2"><label>Document type</label><input name="document_type" class="form-control" placeholder="App\Models\PurchaseOrder" required></div>
<div class="col-md-1"><label>Step</label><input name="approval_step" type="number" min="1" value="1" class="form-control" required></div>
<div class="col-md-2"><label>Branch</label><select name="branch_id" class="form-select"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
<div class="col-md-2"><label>Category</label><select name="category_id" class="form-select"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
<div class="col-md-1"><label>Min</label><input name="min_amount" type="number" step="0.0001" min="0" class="form-control"></div><div class="col-md-1"><label>Max</label><input name="max_amount" type="number" step="0.0001" min="0" class="form-control"></div>
<div class="col-md-2"><label>Permission</label><select name="required_permission" class="form-select" required>@foreach($permissions as $permission)<option value="{{ $permission->code }}">{{ $permission->code }}</option>@endforeach</select></div>
<div class="col-md-1"><label>Escalate (h)</label><input name="escalation_after_hours" type="number" min="1" max="8760" class="form-control" placeholder="Off"></div><div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
</form></div></div>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Document</th><th>Step</th><th>Context</th><th>Amount range</th><th>Permission</th><th>Escalation</th><th>Status</th><th>Update</th></tr></thead><tbody>
@forelse($policies as $policy)<tr><form method="POST" action="{{ route('erp.security.approval-policies.update', $policy->id) }}">@csrf @method('PUT')
<td><input name="document_type" value="{{ $policy->document_type }}" class="form-control form-control-sm"></td><td><input name="approval_step" value="{{ $policy->approval_step ?? 1 }}" type="number" min="1" class="form-control form-control-sm"></td>
<td><select name="branch_id" class="form-select form-select-sm"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($policy->branch_id == $branch->id)>{{ $branch->name }}</option>@endforeach</select><select name="category_id" class="form-select form-select-sm mt-1"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($policy->category_id == $category->id)>{{ $category->name }}</option>@endforeach</select></td>
<td><div class="d-flex gap-1"><input name="min_amount" value="{{ $policy->min_amount }}" type="number" step="0.0001" min="0" class="form-control form-control-sm"><input name="max_amount" value="{{ $policy->max_amount }}" type="number" step="0.0001" min="0" class="form-control form-control-sm"></div></td>
<td><select name="required_permission" class="form-select form-select-sm">@foreach($permissions as $permission)<option value="{{ $permission->code }}" @selected($permission->code === $policy->required_permission)>{{ $permission->code }}</option>@endforeach</select></td>
<td><input name="escalation_after_hours" value="{{ $policy->escalation_after_hours }}" type="number" min="1" max="8760" class="form-control form-control-sm" placeholder="Off"></td>
<td><select name="is_active" class="form-select form-select-sm"><option value="1" @selected($policy->is_active)>Active</option><option value="0" @selected(!$policy->is_active)>Inactive</option></select></td><td><button class="btn btn-sm btn-outline-primary">Save</button></td></form></tr>
@empty<tr><td colspan="8" class="text-center text-muted">No approval policies configured.</td></tr>@endforelse
</tbody></table></div></div></div></div></div>
@endsection
