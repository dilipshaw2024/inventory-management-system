@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
<div class="page-title-box"><h4>Notifications</h4><p class="text-muted">Operational alerts for your ERP workspace.</p></div>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Alert</th><th>Product</th><th>Batch</th><th>Expiry</th><th>Stock</th><th>Created</th><th>Status</th></tr></thead><tbody>
@forelse($notifications as $notification)@php($data = $notification->data)<tr class="{{ $notification->read_at ? '' : 'table-warning' }}"><td>{{ $data['alert_type'] ?? $notification->type }}</td><td>{{ $data['product'] ?? '—' }}</td><td>{{ $data['batch_no'] ?? '—' }}</td><td>{{ $data['expiry_date'] ?? '—' }}</td><td>{{ isset($data['balance']) ? number_format((float) $data['balance'], 3) : '—' }}</td><td>{{ optional($notification->created_at)->format('Y-m-d H:i') }}</td><td>@if($notification->read_at) Read @else<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="btn btn-sm btn-outline-primary">Mark read</button></form>@endif</td></tr>@empty<tr><td colspan="7" class="text-center">No notifications.</td></tr>@endforelse
</tbody></table></div>{{ $notifications->links() }}</div></div></div></div>
@endsection
