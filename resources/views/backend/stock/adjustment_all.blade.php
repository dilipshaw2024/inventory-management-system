@extends('admin.admin_master')

@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><h4 class="mb-sm-0">Inventory Adjustments</h4><a href="{{ route('inventory.adjustments.create') }}" class="btn btn-primary">New adjustment</a></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered table-hover"><thead><tr><th>No.</th><th>Date</th><th>Reason</th><th>Created by</th><th>Status</th><th>Action</th></tr></thead><tbody>
    @forelse($adjustments as $adjustment)
    <tr><td>{{ $adjustment->adjustment_no }}</td><td>{{ optional($adjustment->date)->format('d-m-Y') }}</td><td>{{ $adjustment->reason_code }}</td><td>{{ data_get($adjustment, 'creator.name', 'System') }}</td><td>{{ ucfirst($adjustment->status) }} @if($adjustment->status === 'rejected')<small class="d-block text-muted">{{ $adjustment->rejection_reason }}</small>@endif</td><td>@if($adjustment->status === 'pending')<form method="POST" action="{{ route('inventory.adjustments.approve', $adjustment->id) }}" class="d-inline">@csrf<button class="btn btn-success btn-sm">Approve</button></form> <form method="POST" action="{{ route('inventory.adjustments.reject', $adjustment->id) }}" class="d-inline">@csrf<input name="rejection_reason" value="Rejected by approver" class="form-control form-control-sm d-inline-block" style="width:150px" required maxlength="2000"><button class="btn btn-outline-warning btn-sm">Reject</button></form>@else—@endif</td></tr>
    @empty <tr><td colspan="6" class="text-center">No adjustments found.</td></tr>@endforelse
    </tbody></table></div>{{ $adjustments->links() }}</div></div>
</div></div>
@endsection
