@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box"><h4>Production Suggestions</h4><p class="text-muted">Suggestions use tenant-scoped ledger availability, open production supply, and BOM component availability.</p></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered">
        <thead><tr><th>BOM</th><th>Finished product</th><th>Current</th><th>Open purchase</th><th>Open production</th><th>Suggested</th><th>Max producible</th><th>Material status</th><th>Action</th></tr></thead><tbody>
        @forelse($suggestions as $row)
            <tr><td>{{ $row['bom']->code }}</td><td>{{ $row['bom']->product->name }}</td><td>{{ number_format($row['available'], 3) }}</td><td>{{ number_format($row['open_purchase_quantity'], 3) }}</td><td>{{ number_format($row['open_quantity'], 3) }}</td><td>{{ number_format($row['suggested'], 3) }}</td><td>{{ number_format($row['max_build'], 3) }}</td><td>@if($row['shortages'])<span class="text-danger">{{ implode(', ', $row['shortages']) }}</span>@else<span class="text-success">Components available</span>@endif</td><td>@if($row['suggested'] > 0 && $row['max_build'] > 0)<a class="btn btn-sm btn-primary" href="{{ route('manufacturing.orders.create', ['bom_id' => $row['bom']->id, 'quantity' => min($row['suggested'], $row['max_build'])]) }}">Create order</a>@else—@endif</td></tr>
        @empty
            <tr><td colspan="9" class="text-center">No production suggestions.</td></tr>
        @endforelse
        </tbody></table></div></div></div></div>
</div></div>
@endsection
