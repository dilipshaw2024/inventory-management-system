@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box"><h4>Customer Pricing</h4><p class="text-muted">Maintain negotiated customer, group, and channel prices. Deactivation preserves pricing history.</p></div>
    <div class="card mb-3"><div class="card-body"><form method="POST" action="{{ route('sales.customer.prices.store') }}" class="row g-2">@csrf
        <div class="col-md-3"><select name="customer_id" class="form-select"><option value="">Customer (optional)</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><input name="customer_group" class="form-control" placeholder="Customer group"></div><div class="col-md-2"><input name="sales_channel" class="form-control" placeholder="Sales channel"></div>
        <div class="col-md-3"><select name="product_id" class="form-select" required><option value="">Product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
        <div class="col-md-1"><input name="minimum_quantity" type="number" min="0.000001" step="0.000001" value="1" class="form-control" placeholder="Min qty" required></div><div class="col-md-2"><input name="unit_price" type="number" min="0" step="0.000001" class="form-control" placeholder="Price" required></div>
        <div class="col-md-1"><input name="discount_percent" type="number" min="0" max="100" step="0.0001" value="0" class="form-control" placeholder="Disc %"></div><div class="col-md-1"><input name="currency_code" value="USD" maxlength="3" class="form-control" required></div><div class="col-md-1"><input name="starts_on" type="date" class="form-control" title="Starts"></div><div class="col-md-1"><input name="ends_on" type="date" class="form-control" title="Ends"></div><div class="col-md-1"><button class="btn btn-primary w-100">Save</button></div>
    </form></div></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Customer</th><th>Group/channel</th><th>Product</th><th>Min qty</th><th>Price</th><th>Discount</th><th>Currency</th><th>Validity</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($prices as $price)<tr><td>{{ data_get($price, 'customer.name', 'Any customer') }}</td><td>{{ $price->customer_group ?: '—' }} / {{ $price->sales_channel ?: '—' }}</td><td>{{ data_get($price, 'product.name', 'N/A') }}</td><td>{{ $price->minimum_quantity }}</td><td>{{ $price->unit_price }}</td><td>{{ $price->discount_percent }}%</td><td>{{ $price->currency_code }}</td><td>{{ optional($price->starts_on)->format('d-m-Y') ?: 'Any' }} — {{ optional($price->ends_on)->format('d-m-Y') ?: 'Open' }}</td><td>{{ $price->is_active ? 'Active' : 'Inactive' }}</td><td>@if($price->is_active)<form method="POST" action="{{ route('sales.customer.prices.deactivate', $price->id) }}">@csrf<button class="btn btn-sm btn-outline-danger">Deactivate</button></form>@endif</td></tr>@empty
    <tr><td colspan="10" class="text-center">No customer price agreements found.</td></tr>@endforelse
    </tbody></table></div>{{ $prices->links() }}</div></div>
</div></div>
@endsection
