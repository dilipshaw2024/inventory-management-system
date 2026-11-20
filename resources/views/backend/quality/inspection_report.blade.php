@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
        <h4 class="mb-sm-0">Quality Inspection Report</h4>
        <a class="btn btn-outline-success" href="{{ route('quality.inspections.export', array_merge(['from' => $from, 'to' => $to], request()->except(['page', 'from', 'to']))) }}">Export CSV</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="GET" action="{{ route('quality.inspections.report') }}" class="row g-2 mb-3">
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="{{ $from }}"></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="{{ $to }}"></div>
            <div class="col-md-2"><label class="form-label">Product</label><select name="product_id" class="form-select"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Location</label><select name="location_id" class="form-select"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->code }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Type</label><select name="inspection_type" class="form-select"><option value="">All types</option>@foreach(['receiving','in_process','final','return'] as $type)<option value="{{ $type }}" @selected(request('inspection_type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All statuses</option>@foreach(['pending','in_progress','passed','failed','conditionally_accepted'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
            <div class="col-12"><button class="btn btn-primary">Apply filters</button></div>
        </form>
        <div class="row g-2 mb-3">
            <div class="col-md-2"><div class="alert alert-info mb-0">Inspections<br><strong>{{ $summary['inspection_count'] }}</strong></div></div>
            <div class="col-md-2"><div class="alert alert-success mb-0">Passed<br><strong>{{ $summary['passed_count'] }}</strong></div></div>
            <div class="col-md-2"><div class="alert alert-danger mb-0">Failed<br><strong>{{ $summary['failed_count'] }}</strong></div></div>
            <div class="col-md-2"><div class="alert alert-warning mb-0">Pending<br><strong>{{ $summary['pending_count'] }}</strong></div></div>
            <div class="col-md-2"><div class="alert alert-secondary mb-0">Pass rate<br><strong>{{ $summary['pass_rate_percent'] === null ? 'N/A' : number_format($summary['pass_rate_percent'], 2).'%' }}</strong></div></div>
            <div class="col-md-2"><div class="alert alert-light mb-0">Sample / inspected<br><strong>{{ number_format($summary['sample_quantity'], 2) }} / {{ number_format($summary['inspected_quantity'], 2) }}</strong></div></div>
        </div>
        <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Inspection</th><th>Product</th><th>Type</th><th>Location</th><th>Status</th><th>Disposition</th><th>Sample</th><th>Quantity</th><th>Created</th></tr></thead><tbody>
        @forelse($inspections as $inspection)<tr><td>{{ $inspection->inspection_no }}</td><td>{{ $inspection->product?->name }}</td><td>{{ ucfirst(str_replace('_', ' ', $inspection->plan?->inspection_type ?: 'unspecified')) }}</td><td>{{ $inspection->location?->code ?: 'Unassigned' }}</td><td>{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</td><td>{{ $inspection->disposition ?: '—' }}</td><td>{{ $inspection->sample_quantity }}</td><td>{{ $inspection->quantity }}</td><td>{{ optional($inspection->created_at)->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="9" class="text-center">No inspections matched the selected filters.</td></tr>@endforelse
        </tbody></table></div>{{ $inspections->links() }}
    </div></div>
</div></div>
@endsection
