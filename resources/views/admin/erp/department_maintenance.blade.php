    <div class="card"><div class="card-body">
        <h5>Maintain departments</h5>
        <p class="text-muted">Edit department details or deactivate a department after its active users have been reassigned.</p>
        @foreach($companies as $company)
            @foreach($company->departments as $department)
                <form method="POST" action="{{ route('erp.organization.department.update', $department->id) }}" class="row g-2 align-items-end border-bottom py-2">
                    @csrf
                    @method('PATCH')
                    <div class="col-md-4"><small class="text-muted">{{ $company->name }}</small><input name="name" value="{{ $department->name }}" class="form-control" required></div>
                    <div class="col-md-3"><label class="small">Code</label><input name="code" value="{{ $department->code }}" class="form-control" required></div>
                    <div class="col-md-2 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($department->is_active)><label class="form-check-label">Active</label></div>
                    <div class="col-md-3"><button class="btn btn-sm btn-primary">Save</button>@if($department->is_active)<button type="submit" formaction="{{ route('erp.organization.department.deactivate', $department->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Deactivate this department?')">Deactivate</button>@endif</div>
                </form>
            @endforeach
        @endforeach
    </div></div>
