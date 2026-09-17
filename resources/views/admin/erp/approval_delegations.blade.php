@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
<div class="page-title-box"><h4>Approval Delegations</h4><p class="text-muted">Temporarily delegate approval authority. The delegate must still be different from the document creator.</p></div>
<div class="card mb-3"><div class="card-body"><form method="POST" action="{{ route('erp.security.approval-delegations.store') }}" class="row g-2">@csrf
<div class="col-md-2"><select name="delegator_id" class="form-select" required><option value="">Delegator</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
<div class="col-md-2"><select name="delegate_id" class="form-select" required><option value="">Delegate</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
<div class="col-md-2"><input name="starts_at" type="datetime-local" class="form-control" required></div><div class="col-md-2"><input name="ends_at" type="datetime-local" class="form-control" required></div><div class="col-md-2"><input name="document_types[]" class="form-control" placeholder="Document class (optional)"></div><div class="col-md-2"><input name="reason" class="form-control" placeholder="Reason"></div><div class="col-md-2"><button class="btn btn-primary">Create delegation</button></div>
</form></div></div>
<div class="card"><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>Delegator</th><th>Delegate</th><th>Documents</th><th>Period</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($delegations as $delegation)<tr><td>{{ data_get($delegation, 'delegator.name', 'N/A') }}</td><td>{{ data_get($delegation, 'delegate.name', 'N/A') }}</td><td>{{ $delegation->document_types ? implode(', ', $delegation->document_types) : 'All controlled documents' }}</td><td>{{ optional($delegation->starts_at)->format('Y-m-d H:i') }} — {{ optional($delegation->ends_at)->format('Y-m-d H:i') }}</td><td>{{ $delegation->reason ?: '—' }}</td><td>{{ $delegation->is_active ? 'Active' : 'Inactive' }}</td><td>@if($delegation->is_active)<form method="POST" action="{{ route('erp.security.approval-delegations.deactivate', $delegation->id) }}">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate</button></form>@endif</td></tr>@empty
<tr><td colspan="7" class="text-center">No approval delegations configured.</td></tr>@endforelse
</tbody></table>{{ $delegations->links() }}</div></div>
</div></div>
@endsection
