@extends('admin.admin_master')

@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box">
            <h4>Inventory Valuation</h4>
            <p class="text-muted">Value cost layers at the selected product, category, location, batch, accounting dimensions, fiscal period, and as-of date.</p>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('stock.valuation') }}" class="row g-2 align-items-end">
                    <div class="col-md-2"><label>Product</label><select name="product_id" class="form-select"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label>Category</label><select name="category_id" class="form-select"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label>Location</label><select name="location_id" class="form-select"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->code }} — {{ $location->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label>Batch/Lot</label><select name="batch_id" class="form-select"><option value="">All batches</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected(request('batch_id') == $batch->id)>{{ $batch->product->name }} — {{ $batch->batch_no ?: $batch->lot_no }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label>Department</label><select name="department_id" class="form-select"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->code }} — {{ $department->name }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label>Cost center</label><select name="cost_center_id" class="form-select"><option value="">All cost centers</option>@foreach($costCenters as $costCenter)<option value="{{ $costCenter->id }}" @selected(request('cost_center_id') == $costCenter->id)>{{ $costCenter->code }} — {{ $costCenter->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label>Fiscal period</label><select name="fiscal_period_id" class="form-select"><option value="">All periods</option>@foreach($fiscalPeriods as $period)<option value="{{ $period->id }}" @selected(request('fiscal_period_id') == $period->id)>{{ $period->name }} ({{ $period->ends_on->format('Y-m-d') }})</option>@endforeach</select></div>
                    <div class="col-md-2"><label>As of</label><input name="as_of" type="date" value="{{ request('as_of') }}" class="form-control"></div>
                    <div class="col-md-1"><button class="btn btn-primary">Filter</button></div>
                    <div class="col-md-2"><a class="btn btn-outline-success w-100" href="{{ route('stock.valuation.export', array_merge(request()->query(), ['format' => 'csv'])) }}">Export CSV</a></div>
                    <div class="col-md-2"><a class="btn btn-outline-secondary w-100" target="_blank" href="{{ route('stock.valuation.pdf', array_merge(request()->query(), ['format' => 'pdf'])) }}">Print / PDF</a></div>
                </form>
            </div>
        </div>
        @if($periodDrilldown)
            @php($periodSummary = $periodDrilldown['summary'])
            <div class="card mb-3 border-info">
                <div class="card-body">
                    <h5 class="card-title">Accounting-period reconciliation</h5>
                    <p class="text-muted mb-3">Opening and closing valuation for the selected fiscal period. Variance should be investigated before period close.</p>
                    <div class="row g-2">
                        <div class="col-md-2"><small class="text-muted d-block">Opening value</small><strong>{{ number_format((float) $periodSummary['opening_value'], 2) }}</strong></div>
                        <div class="col-md-2"><small class="text-muted d-block">Inbound value</small><strong>{{ number_format((float) $periodSummary['inbound_value'], 2) }}</strong></div>
                        <div class="col-md-2"><small class="text-muted d-block">Outbound / COGS</small><strong>{{ number_format((float) $periodSummary['outbound_value'], 2) }}</strong></div>
                        <div class="col-md-2"><small class="text-muted d-block">Expected closing</small><strong>{{ number_format((float) $periodSummary['expected_closing_value'], 2) }}</strong></div>
                        <div class="col-md-2"><small class="text-muted d-block">Cost-layer closing</small><strong>{{ number_format((float) $periodSummary['closing_value'], 2) }}</strong></div>
                        <div class="col-md-2"><small class="text-muted d-block">Unexplained variance</small><strong class="{{ abs((float) $periodSummary['unexplained_variance']) > 0.000001 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $periodSummary['unexplained_variance'], 2) }}</strong></div>
                    </div>
                </div>
            </div>
        @endif
        <div class="card">
            <div class="card-body">
                <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Category</th><th>Quantity balance</th><th>Cost-layer value</th></tr></thead><tbody>
                    @forelse($valuation as $product)
                        <tr><td>{{ $product->name }}</td><td>{{ data_get($product, 'category.name', 'N/A') }}</td><td>{{ $product->filtered_quantity ?? $product->quantity }}</td><td>{{ number_format((float) $product->ledger_value, 2) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-center">No products found.</td></tr>
                    @endforelse
                </tbody></table></div>
                {{ $valuation->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
