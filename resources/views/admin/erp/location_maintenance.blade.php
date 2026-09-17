    <div class="card"><div class="card-body">
        <h5>Maintain storage locations</h5>
        <p class="text-muted">Edit location metadata or deactivate unused locations. Active child locations must be handled first.</p>
        @foreach($companies as $company)
            @foreach($company->branches as $branch)
                @foreach($branch->warehouses as $warehouse)
                    @foreach($warehouse->locations as $location)
                        <form method="POST" action="{{ route('erp.organization.location.update', $location->id) }}" class="row g-2 align-items-end border-bottom py-2">
                            @csrf
                            @method('PATCH')
                            <div class="col-md-2"><small class="text-muted">{{ $warehouse->name }}</small><input name="name" value="{{ $location->name }}" class="form-control" required></div>
                            <div class="col-md-1"><label class="small">Code</label><input name="code" value="{{ $location->code }}" class="form-control" required></div>
                            <div class="col-md-1"><label class="small">Type</label><select name="type" class="form-select">@foreach(['zone','rack','shelf','bin'] as $type)<option value="{{ $type }}" @selected($location->type === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div>
                            <div class="col-md-2"><label class="small">Parent</label><select name="parent_id" class="form-select"><option value="">None</option>@foreach($warehouse->locations->where('id', '<>', $location->id) as $parent)<option value="{{ $parent->id }}" @selected($location->parent_id == $parent->id)>{{ $parent->code }} ({{ $parent->type }})</option>@endforeach</select></div>
                            <div class="col-md-1"><label class="small">Qty cap.</label><input name="capacity" type="number" min="0" step="0.000001" value="{{ $location->capacity }}" class="form-control"></div>
                            <div class="col-md-1"><label class="small">Kg cap.</label><input name="capacity_weight_kg" type="number" min="0" step="0.000001" value="{{ $location->capacity_weight_kg }}" class="form-control"></div>
                            <div class="col-md-1"><label class="small">m³ cap.</label><input name="capacity_volume_m3" type="number" min="0" step="0.000001" value="{{ $location->capacity_volume_m3 }}" class="form-control"></div>
                            <div class="col-md-1 form-check"><input name="is_active" value="1" type="checkbox" class="form-check-input" @checked($location->is_active)><label class="form-check-label">Active</label></div>
                            <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button>@if($location->is_active)<button type="submit" formaction="{{ route('erp.organization.location.deactivate', $location->id) }}" formmethod="POST" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Deactivate this location?')">Deactivate</button>@endif</div>
                        </form>
                    @endforeach
                @endforeach
            @endforeach
        @endforeach
    </div></div>
