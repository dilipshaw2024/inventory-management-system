@extends('admin.admin_master')

@section('admin')
<div class="container-fluid py-3">
    <h4>Recurring journals</h4>
    <p class="text-muted">Templates generate balanced posted journals on their due date. Closed fiscal periods are reported by the scheduled command and retried on the next run.</p>
    @if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-4"><div class="card-body">
        <form method="POST" action="{{ route('erp.accounting.recurring-journals.store') }}">
            @csrf
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label">Name</label><input name="name" class="form-control" required value="{{ old('name') }}"></div>
                <div class="col-md-3"><label class="form-label">Description</label><input name="description" class="form-control" value="{{ old('description') }}"></div>
                <div class="col-md-2"><label class="form-label">Frequency</label><select name="frequency" class="form-control"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="daily">Daily</option></select></div>
                <div class="col-md-1"><label class="form-label">Every</label><input type="number" min="1" name="interval" class="form-control" value="1"></div>
                <div class="col-md-3"><label class="form-label">Starts / next run</label><input type="date" name="starts_on" class="form-control mb-1" required value="{{ old('starts_on', now()->toDateString()) }}"><input type="date" name="next_run_on" class="form-control" value="{{ old('next_run_on', now()->toDateString()) }}"></div>
            </div>
            <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead><tbody>
                @for($i = 0; $i < 4; $i++)<tr><td><select name="lines[{{ $i }}][account_id]" class="form-control"><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>@endforeach</select></td><td><input name="lines[{{ $i }}][debit]" type="number" step="0.000001" min="0" class="form-control"></td><td><input name="lines[{{ $i }}][credit]" type="number" step="0.000001" min="0" class="form-control"></td><td><input name="lines[{{ $i }}][description]" class="form-control"></td></tr>@endfor
            </tbody></table></div>
            <button class="btn btn-primary">Create template</button>
        </form>
    </div></div>
    <div class="card"><div class="card-body table-responsive"><table class="table"><thead><tr><th>Name</th><th>Schedule</th><th>Next run</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @forelse($templates as $template)<tr><td>{{ $template->name }}</td><td>{{ ucfirst($template->frequency) }} / {{ $template->interval }}</td><td>{{ optional($template->next_run_on)->toDateString() }}</td><td>{{ $template->is_active ? 'Active' : 'Inactive' }}</td><td>@if($template->is_active)<form method="POST" action="{{ route('erp.accounting.recurring-journals.deactivate', $template->id) }}">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate</button></form>@endif</td></tr>@empty<tr><td colspan="5">No recurring journal templates.</td></tr>@endforelse
    </tbody></table>{{ $templates->links() }}</div></div>
</div>
@endsection
