@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><h4 class="mb-sm-0">Inventory Aging</h4></div>
    <div class="card"><div class="card-body">
        <div class="d-flex justify-content-end gap-2 mb-3">
            <a class="btn btn-outline-success" href="{{ route('stock.aging.export', array_merge(request()->query(), ['format' => 'csv'])) }}">Export CSV</a>
            <a class="btn btn-outline-primary" href="{{ route('stock.aging.export', array_merge(request()->query(), ['format' => 'xlsx'])) }}">Export XLSX</a>
        </div>
        <form method="GET" action="{{ route('stock.aging') }}" class="row g-2 mb-3">
            <div class="col-md-2"><label class="form-label">As of</label><input type="date" name="as_of" class="form-control" value="{{ $asOf }}"></div>
            <div class="col-md-3"><label class="form-label">Product</label><select name="product_id" class="form-select"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Category</label><select name="category_id" class="form-select"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Location</label><select name="location_id" class="form-select"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->code }}</option>@endforeach</select></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Filter</button></div>
        </form>
        <div class="row g-2 mb-3"><div class="col-md-3"><div class="alert alert-info mb-0">Quantity: {{ number_format((float) $summary->sum('quantity'), $decimalPrecision) }}</div></div><div class="col-md-3"><div class="alert alert-secondary mb-0">Value: {{ number_format((float) $summary->sum('value'), 2) }}</div></div>@foreach($summary as $bucket => $totals)<div class="col-md-2"><div class="border rounded p-2"><small>{{ $bucket }} days</small><br><strong>{{ number_format((float) $totals['quantity'], $decimalPrecision) }}</strong></div></div>@endforeach</div>
        <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Product</th><th>SKU</th><th>Warehouse</th><th>Location</th><th>Batch</th><th>Received</th><th>Age</th><th>Bucket</th><th>Qty</th><th>Unit cost</th><th>Value</th></tr></thead><tbody>
        @forelse($paged as $row)<tr><td>{{ $row['product'] }}</td><td>{{ $row['sku'] }}</td><td>{{ $row['warehouse'] ?: 'N/A' }}</td><td>{{ $row['location'] ?: 'N/A' }}</td><td>{{ $row['batch'] ?: 'N/A' }}</td><td>{{ $row['received_at'] }}</td><td>{{ $row['age_days'] }}</td><td>{{ $row['age_bucket'] }}</td><td>{{ number_format((float) $row['quantity'], $decimalPrecision) }}</td><td>{{ number_format((float) $row['unit_cost'], 2) }}</td><td>{{ number_format((float) $row['value'], 2) }}</td></tr>@empty<tr><td colspan="11" class="text-center">No remaining cost-layer stock matched the filters.</td></tr>@endforelse
        </tbody></table></div>{{ $paged->withQueryString()->links() }}
    </div></div>
</div></div>
@endsection
