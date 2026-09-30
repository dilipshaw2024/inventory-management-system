@extends('admin.admin_master')
@section('admin')
<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4>Warehouse Pick Waves</h4><p class="text-muted mb-0">Review the optimized pending pick list and create a planned wave.</p></div>
    </div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if(session('message'))<div class="alert alert-{{ session('alert-type', 'success') }}">{{ session('message') }}</div>@endif
    <div class="card mb-3"><div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-3"><label class="form-label">Warehouse</label><select name="warehouse_id" class="form-select" required>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected($warehouseId === $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Location ID</label><input name="location_id" value="{{ request('location_id') }}" type="number" min="1" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Delivery date</label><input name="date" value="{{ request('date') }}" type="date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Sort</label><select name="sort_by" class="form-select"><option value="location" @selected($sortBy === 'location')>Location</option><option value="product" @selected($sortBy === 'product')>Product</option><option value="delivery_date" @selected($sortBy === 'delivery_date')>Delivery date</option></select></div>
            <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Refresh</button></div>
        </form>
    </div></div>
    <form method="post" action="{{ route('warehouse.picking.waves.create') }}">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
        <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Eligible deliveries ({{ $deliveries->count() }})</span><button class="btn btn-success btn-sm" type="submit">Create planned wave</button></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th></th><th>Sequence</th><th>Delivery</th><th>Date</th><th>Location</th><th>Customer</th><th>Items</th></tr></thead><tbody>@forelse($deliveries as $index => $delivery)<tr><td><input type="checkbox" name="delivery_ids[]" value="{{ $delivery->id }}" checked></td><td>{{ $index + 1 }}</td><td>{{ $delivery->delivery_no }}</td><td>{{ optional($delivery->date)->toDateString() }}</td><td>{{ $delivery->location?->code ?: '—' }}</td><td>{{ $delivery->salesOrder?->customer?->name ?: '—' }}</td><td>{{ $delivery->lines->map(fn ($line) => $line->product?->name.' × '.$line->delivered_qty)->implode(', ') }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">No eligible pending deliveries.</td></tr>@endforelse</tbody></table></div></div>
        <div class="row g-2 mb-4"><div class="col-md-4"><label class="form-label">Wave date</label><input name="wave_date" type="date" value="{{ now()->toDateString() }}" class="form-control"></div><div class="col-md-5"><label class="form-label">External reference (optional)</label><input name="external_reference" maxlength="150" class="form-control"></div></div>
    </form>
    <div class="card"><div class="card-header">Recent waves</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Wave</th><th>Warehouse</th><th>Status</th><th>Deliveries</th><th>Date</th></tr></thead><tbody>@foreach($waves as $wave)<tr><td>{{ $wave->wave_no }}</td><td>{{ $wave->warehouse?->name }}</td><td>{{ ucfirst($wave->status) }}</td><td>{{ $wave->deliveries->count() }}</td><td>{{ optional($wave->wave_date)->toDateString() }}</td></tr>@endforeach</tbody></table></div><div class="card-footer">{{ $waves->withQueryString()->links() }}</div></div>
</div>
@endsection
