@extends('admin.admin_master')

@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
            <h4 class="mb-sm-0">Stock Movement Ledger</h4>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('stock.movements') }}" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-select">
                            <option value="">All products</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Movement</label>
                        <select name="movement_type" class="form-select">
                            <option value="">All movements</option>
                            @foreach(['opening','receipt','issue','transfer_in','transfer_out','adjustment_in','adjustment_out','return_in','return_out','scrap','quarantine_in','quarantine_out','reservation','release'] as $type)
                                <option value="{{ $type }}" @selected(request('movement_type') === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" value="{{ request('from') }}" class="form-control"></div>
                    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" value="{{ request('to') }}" class="form-control"></div>
                    <div class="col-md-3"><button class="btn btn-primary">Filter</button> <a href="{{ route('stock.movements') }}" class="btn btn-light">Reset</a> <a href="{{ route('stock.movements.export', request()->query()) }}" class="btn btn-outline-success">Export CSV</a></div>
                </form>
            </div>
        </div>

        <div class="card"><div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead><tr><th>Date</th><th>Product</th><th>Movement</th><th>Quantity</th><th>Unit cost</th><th>Opening balance</th><th>Closing balance</th><th>Reference</th><th>Location</th><th>User</th></tr></thead>
                    <tbody>
                    @forelse($movements as $movement)
                        <tr>
                            <td>{{ optional($movement->posted_at)->format('d-m-Y H:i') }}</td>
                            <td>{{ data_get($movement, 'product.name', 'N/A') }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $movement->movement_type)) }}</td>
                            <td>{{ $movement->quantity }}</td>
                            <td>{{ $movement->unit_cost ?? '—' }}</td>
                            <td>{{ number_format((float) $movement->opening_balance, 6) }}</td>
                            <td>{{ number_format((float) $movement->closing_balance, 6) }}</td>
                            <td>{{ $movement->reference_no ?? '—' }}</td>
                            <td>{{ data_get($movement, 'location.code', 'Unassigned') }}</td>
                            <td>{{ data_get($movement, 'creator.name', 'System') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center">No stock movements found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $movements->links() }}
        </div></div>
    </div>
</div>
@endsection
