    <div class="card"><div class="card-body">
        <h5>Maintain companies</h5>
        <p class="text-muted">Edit company details or deactivate a company after its active branches and departments have been handled.</p>
        @foreach($companies as $company)
            <form method="POST" action="{{ route('erp.organization.company.update', $company->id) }}" class="row g-2 align-items-end border-bottom py-2">
                @csrf
                @method('PATCH')
                <div class="col-md-2"><input name="name" value="{{ $company->name }}" class="form-control" required></div>
                <div class="col-md-1"><label class="small">Code</label><input name="code" value="{{ $company->code }}" class="form-control" required></div>
                <div class="col-md-1"><label class="small">Currency</label><input name="base_currency" value="{{ $company->base_currency }}" maxlength="3" class="form-control" required></div>
                <div class="col-md-2"><label class="small">Tax number</label><input name="tax_number" value="{{ $company->tax_number }}" class="form-control"></div>
                <div class="col-md-2"><label class="small">Email</label><input name="email" type="email" value="{{ $company->email }}" class="form-control"></div>
                <div class="col-md-2"><label class="small">Phone</label><input name="phone" value="{{ $company->phone }}" class="form-control"></div>
                <div class="col-md-1 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($company->is_active)><label class="form-check-label">Active</label></div>
                <div class="col-md-1"><button class="btn btn-sm btn-primary">Save</button></div>
                <div class="col-12"><input name="address" value="{{ $company->address }}" class="form-control mb-2" placeholder="Address">@if($company->is_active)<button type="submit" formaction="{{ route('erp.organization.company.deactivate', $company->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this company?')">Deactivate company</button>@endif</div>
            </form>
        @endforeach
    </div></div>
