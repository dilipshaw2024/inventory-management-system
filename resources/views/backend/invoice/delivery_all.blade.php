@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><div><h4>Deliveries</h4><p class="text-muted">Complete picking and packing before dispatch approval.</p></div><a href="{{ route('fulfillment.deliveries.create') }}" class="btn btn-primary">New delivery</a></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Delivery</th><th>Sales order</th><th>Customer</th><th>Date</th><th>Operations</th><th>Status / action</th></tr></thead><tbody>
    @forelse($deliveries as $delivery)
        @php($pick = $delivery->operations->firstWhere('operation_type', 'pick'))
        @php($pack = $delivery->operations->firstWhere('operation_type', 'pack'))
        @php($cancelled = $delivery->fulfillment_status === 'cancelled')
        <tr>
            <td>{{ $delivery->delivery_no }}</td><td>{{ data_get($delivery, 'salesOrder.order_no', 'N/A') }}</td><td>{{ data_get($delivery, 'salesOrder.customer.name', 'N/A') }}</td><td>{{ optional($delivery->date)->format('d-m-Y') }}</td>
            <td>
                @if($cancelled)<span class="text-muted">No operations</span>
                @else
                    @if($pick?->status !== 'completed')<form method="POST" action="{{ route('fulfillment.deliveries.operation', [$delivery->id, 'pick']) }}" class="d-inline-flex align-items-center gap-1">@csrf @foreach($delivery->lines as $deliveryLine)<label class="small text-muted">{{ $deliveryLine->product->name }}<input name="picked_quantity[{{ $deliveryLine->id }}]" type="number" min="0" step="0.000001" value="{{ $deliveryLine->delivered_qty }}" class="form-control form-control-sm" style="width:95px" required></label>@endforeach<button class="btn btn-sm btn-outline-primary">Confirm pick</button></form>@else<span class="badge bg-success">Picked</span>@endif
                    @if($pick?->status === 'completed' && $pack?->status !== 'completed')<form method="POST" action="{{ route('fulfillment.deliveries.operation', [$delivery->id, 'pack']) }}" class="d-inline-flex align-items-center gap-1 ms-1">@csrf @foreach($delivery->lines as $deliveryLine)<label class="small text-muted">{{ $deliveryLine->product->name }}<input name="packed_quantity[{{ $deliveryLine->id }}]" type="number" min="0" step="0.000001" value="{{ $deliveryLine->delivered_qty }}" class="form-control form-control-sm" style="width:95px" required></label>@endforeach<button class="btn btn-sm btn-outline-info">Confirm pack</button></form>@elseif($pack?->status === 'completed')<span class="badge bg-info ms-1">Packed</span>@endif
                @endif
            </td>
            <td>
                @if($cancelled)<span class="badge bg-secondary">Cancelled</span><small class="d-block">{{ $delivery->cancellation_reason }}</small>
                @else
                    {{ ucfirst($delivery->fulfillment_status ?: $delivery->status) }}
                    @if($delivery->status === 'pending' && ($pack?->status === 'completed' || !$delivery->operations->count()))<form method="POST" action="{{ route('fulfillment.deliveries.approve', $delivery->id) }}" class="d-inline ms-1">@csrf<button class="btn btn-success btn-sm">Dispatch & issue</button></form><form method="POST" action="{{ route('fulfillment.deliveries.cancel', $delivery->id) }}" class="d-inline ms-1">@csrf<input name="cancellation_reason" class="form-control form-control-sm d-inline-block" style="width:150px" value="Cancelled by operator" required maxlength="2000"><button class="btn btn-outline-danger btn-sm">Cancel</button></form>@elseif($delivery->fulfillment_status === 'dispatched')<form method="POST" action="{{ route('fulfillment.deliveries.delivered', $delivery->id) }}" class="d-inline ms-1">@csrf<button class="btn btn-outline-success btn-sm">Confirm delivered</button></form><form method="POST" action="{{ route('fulfillment.deliveries.cancel', $delivery->id) }}" class="d-inline ms-1">@csrf<input name="cancellation_reason" class="form-control form-control-sm d-inline-block" style="width:150px" value="Dispatch reversal" required maxlength="2000"><button class="btn btn-outline-danger btn-sm">Cancel & reverse</button></form>@endif
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-center">No deliveries found.</td></tr>
    @endforelse
    </tbody></table></div>{{ $deliveries->links() }}</div></div>
</div></div>
<div class="card mt-3"><div class="card-body"><h5>Approval rejection</h5><p class="text-muted">Reject pending deliveries before inventory is issued.</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Delivery</th><th>Status</th><th>Action</th></tr></thead><tbody>@foreach($deliveries as $pendingDelivery)@if($pendingDelivery->status === 'pending' && ($pendingDelivery->fulfillment_status ?: 'pending') === 'pending')<tr><td>{{ $pendingDelivery->delivery_no }}</td><td>Pending</td><td><form method="POST" action="{{ route('fulfillment.deliveries.reject', $pendingDelivery->id) }}" class="d-inline">@csrf<input name="rejection_reason" value="Rejected by approver" class="form-control form-control-sm d-inline-block" style="width:160px" required maxlength="2000"><button class="btn btn-outline-warning btn-sm">Reject</button></form></td></tr>@endif @endforeach</tbody></table></div></div></div>
@endsection
