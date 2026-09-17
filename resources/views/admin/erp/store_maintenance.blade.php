    <div class="card"><div class="card-body">
        <h5>Maintain stores</h5>
        <p class="text-muted">Edit store settings or deactivate a store after its active sales orders have been completed or cancelled.</p>
        @foreach($companies as $company)
            @foreach($company->branches as $branch)
                @foreach($branch->stores as $store)
                    <form method="POST" action="{{ route('erp.organization.store.update', $store->id) }}" class="row g-2 align-items-end border-bottom py-2">
                        @csrf
                        @method('PATCH')
                        <div class="col-md-2"><small class="text-muted">{{ $branch->name }}</small><input name="name" value="{{ $store->name }}" class="form-control" required></div>
                        <div class="col-md-1"><label class="small">Code</label><input name="code" value="{{ $store->code }}" class="form-control" required></div>
                        <div class="col-md-2"><label class="small">Warehouse</label><select name="warehouse_id" class="form-select"><option value="">None</option>@foreach($branch->warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected($store->warehouse_id == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div class="col-md-1"><label class="small">Currency</label><input name="currency_code" value="{{ $store->currency_code }}" maxlength="3" class="form-control"></div>
                        <div class="col-md-1"><label class="small">Tax mode</label><select name="default_tax_mode" class="form-select"><option value="">Default</option><option value="exclusive" @selected($store->default_tax_mode === 'exclusive')>Excl.</option><option value="inclusive" @selected($store->default_tax_mode === 'inclusive')>Incl.</option></select></div>
                        <div class="col-md-1 form-check"><input name="allow_negative_stock" value="1" type="checkbox" class="form-check-input" @checked($store->allow_negative_stock)><label class="form-check-label">Negative</label></div>
                        <div class="col-md-1 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($store->is_active)><label class="form-check-label">Active</label></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button>@if($store->is_active)<button type="submit" formaction="{{ route('erp.organization.store.deactivate', $store->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Deactivate this store?')">Deactivate</button>@endif</div>
                        <div class="col-12"><input name="address" value="{{ $store->address }}" class="form-control" placeholder="Address"></div>
                    </form>
                @endforeach
            @endforeach
        @endforeach
    </div></div>
