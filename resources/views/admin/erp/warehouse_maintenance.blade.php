    <div class="card"><div class="card-body">
        <h5>Maintain warehouses</h5>
        <p class="text-muted">Edit warehouse details or deactivate a warehouse after its storage locations have been deactivated.</p>
        @foreach($companies as $company)
            @foreach($company->branches as $branch)
                @foreach($branch->warehouses as $warehouse)
                    <form method="POST" action="{{ route('erp.organization.warehouse.update', $warehouse->id) }}" class="row g-2 align-items-end border-bottom py-2">
                        @csrf
                        @method('PATCH')
                        <div class="col-md-2"><small class="text-muted">{{ $branch->name }}</small><input name="name" value="{{ $warehouse->name }}" class="form-control" required></div>
                        <div class="col-md-2"><label class="small">Code</label><input name="code" value="{{ $warehouse->code }}" class="form-control" required></div>
                        <div class="col-md-2"><label class="small">Type</label><select name="warehouse_type" class="form-select"><option value="standard" @selected(($warehouse->warehouse_type ?: 'standard') === 'standard')>Standard</option><option value="distribution" @selected($warehouse->warehouse_type === 'distribution')>Distribution</option><option value="retail" @selected($warehouse->warehouse_type === 'retail')>Retail</option><option value="manufacturing" @selected($warehouse->warehouse_type === 'manufacturing')>Manufacturing</option><option value="quarantine" @selected($warehouse->warehouse_type === 'quarantine')>Quarantine</option><option value="transit" @selected($warehouse->warehouse_type === 'transit')>Transit</option></select></div><div class="col-md-2"><label class="small">Manager</label><input name="manager_name" value="{{ $warehouse->manager_name }}" class="form-control"></div>
                        <div class="col-md-3"><label class="small">Address</label><input name="address" value="{{ $warehouse->address }}" class="form-control"></div>
                        <div class="col-md-1 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($warehouse->is_active)><label class="form-check-label">Active</label></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button>@if($warehouse->is_active)<button type="submit" formaction="{{ route('erp.organization.warehouse.deactivate', $warehouse->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Deactivate this warehouse?')">Deactivate</button>@endif</div>
                    </form>
                @endforeach
            @endforeach
        @endforeach
    </div></div>
