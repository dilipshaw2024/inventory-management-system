@extends('admin.admin_master')
@section('admin')
<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h4>Carrier Rate Cards</h4><p class="text-muted mb-0">Maintain warehouse shipping rates used for delivery quotes and carrier selection.</p></div></div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if(session('message'))<div class="alert alert-{{ session('alert-type', 'success') }}">{{ session('message') }}</div>@endif
    <div class="card mb-3"><div class="card-header">Add rate card</div><div class="card-body">
        <form method="post" action="{{ route('warehouse.logistics.rate-cards.store') }}" class="row g-2">
            @csrf
            <div class="col-md-2"><label class="form-label">Carrier</label><input name="carrier" class="form-control" required maxlength="255"></div>
            <div class="col-md-2"><label class="form-label">Service code</label><input name="service_code" class="form-control" required maxlength="80"></div>
            <div class="col-md-2"><label class="form-label">Origin zone</label><input name="origin_zone" class="form-control" maxlength="80"></div>
            <div class="col-md-2"><label class="form-label">Destination zone</label><input name="destination_zone" class="form-control" maxlength="80"></div>
            <div class="col-md-2"><label class="form-label">Currency</label><input name="currency_code" class="form-control" value="INR" required maxlength="3"></div>
            <div class="col-md-2"><label class="form-label">External reference</label><input name="external_reference" class="form-control" maxlength="150"></div>
            <div class="col-md-2"><label class="form-label">Min kg</label><input name="min_weight_kg" type="number" step="0.000001" min="0" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Max kg</label><input name="max_weight_kg" type="number" step="0.000001" min="0" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Base amount</label><input name="base_amount" type="number" step="0.000001" min="0" class="form-control" value="0" required></div>
            <div class="col-md-2"><label class="form-label">Per kg</label><input name="per_kg_amount" type="number" step="0.000001" min="0" class="form-control" value="0" required></div>
            <div class="col-md-2"><label class="form-label">Transit days</label><input name="transit_days" type="number" min="0" max="365" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Valid from</label><input name="valid_from" type="date" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Valid until</label><input name="valid_until" type="date" class="form-control"></div>
            <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Create card</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-header">Configured cards ({{ $cards->total() }})</div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Carrier/service</th><th>Zones</th><th>Weight band</th><th>Pricing</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @forelse($cards as $card)
        <tr><td><strong>{{ $card->carrier }}</strong><br><small>{{ $card->service_code }}</small></td><td>{{ $card->origin_zone ?: '*' }} → {{ $card->destination_zone ?: '*' }}</td><td>{{ $card->min_weight_kg ?? 0 }}–{{ $card->max_weight_kg ?? '∞' }} kg</td><td>{{ $card->currency_code }} {{ number_format((float)$card->base_amount, 2) }} + {{ number_format((float)$card->per_kg_amount, 2) }}/kg</td><td>{{ optional($card->valid_from)->toDateString() ?: 'Any' }} – {{ optional($card->valid_until)->toDateString() ?: 'Open' }}</td><td>{{ $card->is_active ? 'Active' : 'Inactive' }}</td><td><details><summary class="btn btn-sm btn-outline-secondary">Edit</summary><form method="post" action="{{ route('warehouse.logistics.rate-cards.update', $card->id) }}" class="mt-2" style="min-width:420px">@csrf @method('PUT')<div class="row g-1"><div class="col-6"><input name="carrier" value="{{ $card->carrier }}" class="form-control form-control-sm" required></div><div class="col-6"><input name="service_code" value="{{ $card->service_code }}" class="form-control form-control-sm" required></div><div class="col-4"><input name="base_amount" value="{{ $card->base_amount }}" type="number" step="0.000001" min="0" class="form-control form-control-sm" required></div><div class="col-4"><input name="per_kg_amount" value="{{ $card->per_kg_amount }}" type="number" step="0.000001" min="0" class="form-control form-control-sm" required></div><div class="col-4"><input name="currency_code" value="{{ $card->currency_code }}" maxlength="3" class="form-control form-control-sm" required></div></div><button class="btn btn-sm btn-primary mt-1">Save</button></form></details>@if($card->is_active)<form method="post" action="{{ route('warehouse.logistics.rate-cards.deactivate', $card->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate</button></form>@endif</td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted">No carrier rate cards configured.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $cards->links() }}</div></div>
</div>
@endsection
