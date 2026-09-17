@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><h4>Inventory Transfers</h4><a href="{{ route('inventory.transfers.create') }}" class="btn btn-primary">New transfer</a></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Transfer no.</th><th>Date</th><th>Status</th><th>Created by</th><th>Lines / receiving</th><th>Shipping</th><th>Variance</th><th>Action</th></tr></thead><tbody>
    @forelse($transfers as $transfer)
        <tr>
            <td>{{ $transfer->transfer_no }}</td><td>{{ optional($transfer->date)->format('d-m-Y') }}</td><td>{{ str_replace('_', ' ', ucfirst($transfer->status)) }}</td><td>{{ data_get($transfer, 'creator.name', 'System') }}</td>
            <td>@foreach($transfer->lines as $line)<small class="d-block">{{ $line->product?->name }}: {{ $line->received_quantity ?? 0 }} / {{ $line->quantity }}</small>@endforeach</td>
            <td>@if($transfer->carrier_name || $transfer->tracking_number || $transfer->expected_arrival)<small class="d-block">{{ $transfer->carrier_name ?: 'Carrier not set' }}</small>@if($transfer->tracking_number)<small class="d-block">Tracking: {{ $transfer->tracking_number }}</small>@endif @if($transfer->expected_arrival)<small class="d-block">ETA: {{ $transfer->expected_arrival->format('d-m-Y') }}</small>@endif @else—@endif</td>
            <td>@if($transfer->variance_status)<span class="badge bg-{{ $transfer->variance_status === 'pending' ? 'warning' : 'success' }}">{{ ucfirst($transfer->variance_status) }}</span>@if($transfer->variance_reason)<small class="d-block">{{ $transfer->variance_reason }}</small>@endif@else—@endif</td>
            <td>
                @if($transfer->status === 'pending')
                    <form method="POST" action="{{ route('inventory.transfers.approve', $transfer->id) }}" class="d-inline">@csrf<button class="btn btn-success btn-sm">Approve</button></form>
                    <form method="POST" action="{{ route('inventory.transfers.reject', $transfer->id) }}" class="d-inline">@csrf<input name="rejection_reason" value="Rejected by approver" class="form-control form-control-sm d-inline-block" style="width:150px" required maxlength="2000"><button class="btn btn-outline-warning btn-sm">Reject</button></form>
                @elseif($transfer->status === 'approved')
                    <form method="POST" action="{{ route('inventory.transfers.dispatch', $transfer->id) }}" class="d-inline">@csrf<button class="btn btn-primary btn-sm">Dispatch</button></form>
                @elseif(in_array($transfer->status, ['in_transit', 'partially_received'], true))
                    <form method="POST" action="{{ route('inventory.transfers.receive', $transfer->id) }}" class="d-inline">@csrf @foreach($transfer->lines as $line)<input name="received_quantity[{{ $line->id }}]" type="hidden" value="{{ max(0, (float) $line->quantity - (float) ($line->received_quantity ?? 0)) }}">@endforeach<input name="receiving_note" type="hidden" value=""><button class="btn btn-info btn-sm">Receive remaining</button></form>
                    @if($transfer->status === 'partially_received')
                        <form method="POST" action="{{ route('inventory.transfers.close-shortage', $transfer->id) }}" class="mt-1">@csrf<div class="input-group input-group-sm"><input name="shortage_reason" class="form-control" placeholder="Shortage reason" required maxlength="2000"><button class="btn btn-warning">Close shortage</button></div></form>
                    @endif
                @elseif($transfer->variance_status === 'pending')
                    <form method="POST" action="{{ route('inventory.transfers.resolve-variance', $transfer->id) }}" class="mt-1">@csrf<div class="input-group input-group-sm"><select name="resolution" class="form-select" required><option value="accepted">Accept variance</option><option value="waived">Waive variance</option></select><input name="variance_reason" class="form-control" placeholder="Reason" required maxlength="2000"><button class="btn btn-warning">Resolve</button></div></form>
                @else—@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-center">No transfers found.</td></tr>
    @endforelse
    </tbody></table></div>{{ $transfers->links() }}</div></div>
</div></div>
@endsection
