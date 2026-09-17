@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><h4>Purchase Orders</h4><a href="{{ route('procurement.orders.create') }}" class="btn btn-primary">New purchase order</a></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>PO no.</th><th>Supplier</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
    @forelse($orders as $order)
        <tr><td>{{ $order->po_no }}</td><td>{{ data_get($order, 'supplier.name', 'N/A') }}</td><td>{{ optional($order->date)->format('d-m-Y') }}</td><td>{{ ucwords(str_replace('_', ' ', $order->status)) }} @if($order->status === 'cancelled')<small class="d-block text-muted">{{ $order->cancellation_reason }}</small>@elseif($order->status === 'rejected')<small class="d-block text-muted">{{ $order->rejection_reason }}</small>@endif</td><td>@if($order->status === 'submitted')<form method="POST" action="{{ route('procurement.orders.approve', $order->id) }}" class="d-inline">@csrf<button class="btn btn-success btn-sm">Approve</button></form><form method="POST" action="{{ route('procurement.orders.reject', $order->id) }}" class="d-inline ms-1">@csrf<input name="rejection_reason" class="form-control form-control-sm d-inline-block" style="width:150px" value="Rejected by approver" required maxlength="2000"><button class="btn btn-outline-warning btn-sm">Reject</button></form>@endif @if(in_array($order->status, ['submitted', 'approved'], true))<form method="POST" action="{{ route('procurement.orders.cancel', $order->id) }}" class="d-inline ms-1">@csrf<input name="cancellation_reason" class="form-control form-control-sm d-inline-block" style="width:150px" value="Cancelled by operator" required maxlength="2000"><button class="btn btn-outline-danger btn-sm">Cancel</button></form>@else@if(!in_array($order->status, ['cancelled', 'rejected'], true))—@endif@endif</td></tr>
    @empty
        <tr><td colspan="5" class="text-center">No purchase orders found.</td></tr>
    @endforelse
    </tbody></table></div>{{ $orders->links() }}</div></div>
</div></div>
@endsection
