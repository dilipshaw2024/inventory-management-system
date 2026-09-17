<div class="card mt-3"><div class="card-body"><h5>Maintain policies</h5><p class="text-muted">Update thresholds or deactivate policies that are no longer used.</p>
@foreach($policies as $policy)
    <div class="border-bottom py-2">
        <form method="POST" action="{{ route('planning.policies.update', $policy->id) }}" class="row g-2 align-items-end">
            @csrf
            @method('PATCH')
            <input type="hidden" name="product_id" value="{{ $policy->product_id }}">
            <input type="hidden" name="location_id" value="{{ $policy->location_id }}">
            <div class="col-md-2"><small class="text-muted">Product / location</small><div>{{ $policy->product->name }} / {{ $policy->location->code }}</div></div>
            <div class="col-md-1"><label class="small">Reorder</label><input name="reorder_point" type="number" min="0" step="0.000001" value="{{ $policy->reorder_point }}" class="form-control form-control-sm" required></div>
            <div class="col-md-1"><label class="small">Safety</label><input name="safety_stock" type="number" min="0" step="0.000001" value="{{ $policy->safety_stock }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><label class="small">Min</label><input name="min_stock" type="number" min="0" step="0.000001" value="{{ $policy->min_stock }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><label class="small">Max</label><input name="max_stock" type="number" min="0" step="0.000001" value="{{ $policy->max_stock }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><label class="small">Lead days</label><input name="lead_time_days" type="number" min="0" value="{{ $policy->lead_time_days }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><label class="small">Buffer days</label><input name="safety_time_days" type="number" min="0" value="{{ $policy->safety_time_days }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><small class="text-muted">Status</small><div>{{ $policy->is_active ? 'Active' : 'Inactive' }}</div></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button></div>
        </form>
        @if($policy->is_active)<form method="POST" action="{{ route('planning.policies.deactivate', $policy->id) }}" class="mt-1">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate policy</button></form>@endif
    </div>
@endforeach
</div></div>
