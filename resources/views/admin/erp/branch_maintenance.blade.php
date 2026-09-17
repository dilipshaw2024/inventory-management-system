    <div class="card"><div class="card-body">
        <h5>Maintain branches</h5>
        <p class="text-muted">Edit branch details or deactivate a branch after its active warehouses and stores have been handled.</p>
        @foreach($companies as $company)
            @foreach($company->branches as $branch)
                <form method="POST" action="{{ route('erp.organization.branch.update', $branch->id) }}" class="row g-2 align-items-end border-bottom py-2">
                    @csrf
                    @method('PATCH')
                    <div class="col-md-2"><small class="text-muted">{{ $company->name }}</small><input name="name" value="{{ $branch->name }}" class="form-control" required></div>
                    <div class="col-md-2"><label class="small">Code</label><input name="code" value="{{ $branch->code }}" class="form-control" required></div>
                    <div class="col-md-2"><label class="small">Tax number</label><input name="tax_number" value="{{ $branch->tax_number }}" class="form-control"></div>
                    <div class="col-md-3"><label class="small">Address</label><input name="address" value="{{ $branch->address }}" class="form-control"></div>
                    <div class="col-md-1"><label class="small">Phone</label><input name="phone" value="{{ $branch->phone }}" class="form-control"></div>
                    <div class="col-md-1 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($branch->is_active)><label class="form-check-label">Active</label></div>
                    <div class="col-md-1"><button class="btn btn-sm btn-primary">Save</button></div>
                    @if($branch->is_active)<div class="col-12"><button type="submit" formaction="{{ route('erp.organization.branch.deactivate', $branch->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this branch?')">Deactivate branch</button></div>@endif
                </form>
            @endforeach
        @endforeach
    </div></div>
