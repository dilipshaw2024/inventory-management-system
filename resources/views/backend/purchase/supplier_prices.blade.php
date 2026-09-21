@extends('admin.admin_master')
@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box">
            <h4>Supplier Pricing</h4>
            <p class="text-muted">Maintain effective supplier-item agreements and quantity breaks. Deactivation preserves pricing history.</p>
        </div>
        <div class="card mb-3"><div class="card-body">
            <form method="POST" action="{{ route('procurement.supplier.prices.store') }}" class="row g-2">
                @csrf
                <div class="col-md-2"><select name="supplier_id" class="form-select" required><option value="">Supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><select name="product_id" class="form-select" required><option value="">Product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
                <div class="col-md-1"><input name="minimum_quantity" type="number" step="0.000001" min="0.000001" value="1" class="form-control" placeholder="Min qty" required></div>
                <div class="col-md-2"><input name="unit_price" type="number" step="0.000001" min="0" class="form-control" placeholder="Unit price" required></div>
                <div class="col-md-1"><input name="currency_code" value="USD" maxlength="3" class="form-control" required></div>
                <div class="col-md-1"><input name="lead_time_days" type="number" min="0" class="form-control" placeholder="Days"></div>
                <div class="col-md-1"><input name="starts_on" type="date" class="form-control" title="Starts"></div>
                <div class="col-md-1"><input name="ends_on" type="date" class="form-control" title="Ends"></div>
                <div class="col-md-1"><button class="btn btn-primary w-100">Save</button></div>
                <div class="col-md-12"><input name="supplier_sku" class="form-control" placeholder="Supplier SKU (optional)"></div>
            </form>
        </div></div>
        <div class="card"><div class="card-body"><div class="table-responsive">
            <table class="table table-bordered">
                <thead><tr><th>Supplier</th><th>Product</th><th>Min qty</th><th>Price</th><th>Currency</th><th>Lead time</th><th>Validity</th><th>Status</th><th>Supplier SKU</th><th>Approval</th><th></th></tr></thead>
                <tbody>
                @forelse($prices as $price)
                    <tr>
                        <td>{{ data_get($price, 'supplier.name', 'N/A') }}</td><td>{{ data_get($price, 'product.name', 'N/A') }}</td><td>{{ $price->minimum_quantity }}</td><td>{{ $price->unit_price }}</td><td>{{ $price->currency_code }}</td><td>{{ $price->lead_time_days !== null ? $price->lead_time_days.' days' : '—' }}</td><td>{{ optional($price->starts_on)->format('d-m-Y') ?: 'Any' }} — {{ optional($price->ends_on)->format('d-m-Y') ?: 'Open' }}</td><td>{{ $price->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $price->supplier_sku ?: '—' }}</td><td>{{ ucfirst($price->approval_status ?? 'approved') }} @if($price->rejection_reason)<small class="text-danger d-block">{{ $price->rejection_reason }}</small>@endif</td>
                        <td>@if(($price->approval_status ?? 'approved') === 'pending')<form method="POST" action="{{ route('procurement.supplier.prices.approve', $price->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">Approve</button></form><form method="POST" action="{{ route('procurement.supplier.prices.reject', $price->id) }}" class="d-inline">@csrf<input type="hidden" name="reason" value="Rejected from supplier pricing screen"><button class="btn btn-sm btn-outline-warning">Reject</button></form>@endif @if($price->is_active)<form method="POST" action="{{ route('procurement.supplier.prices.deactivate', $price->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center">No supplier price agreements found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>{{ $prices->links() }}</div></div>
    </div>
</div>
@endsection
