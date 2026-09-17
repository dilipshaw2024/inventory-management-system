@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-sm-flex align-items-center justify-content-between"><div><h4>Purchase Suggestions</h4><p class="text-muted">Select replenishment lines to create submitted purchase orders. Lines are grouped by supplier.</p></div><form method="GET" class="d-flex gap-2 align-items-end"><div><label class="small">Forecast horizon (days)</label><input name="forecast_horizon_days" type="number" min="1" max="365" value="{{ $forecastHorizon ?? '' }}" class="form-control form-control-sm" placeholder="Optional"></div><button class="btn btn-sm btn-outline-primary">Apply forecast</button>@if($forecastHorizon)<a class="btn btn-sm btn-outline-secondary" href="{{ request()->url() }}">Clear</a>@endif</form></div>
    <form method="POST" action="{{ route('planning.purchase.suggestions.create-orders') }}">
        @csrf
        <div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Select</th><th>Product</th><th>Location</th><th>Supplier</th><th>Current stock</th><th>Reorder level</th><th>Target maximum</th>@if($forecastHorizon)<th>Forecast / confidence</th>@endif<th>Suggested quantity</th><th>Purchase price</th></tr></thead><tbody>
        @forelse($suggestions as $product)
            @php($current = (float) ($product->current_stock ?? $product->quantity))
            @php($target = (float) ($product->target_stock ?? ((float) $product->max_stock > 0 ? $product->max_stock : max((float) $product->reorder_level * 2, 1))) )
            @php($suggested = (float) ($product->current_stock !== null ? $product->quantity : max($target - $current, 0)))
            <tr><td>@if($product->supplier_id)<input type="checkbox" name="product_id[]" value="{{ $product->id }}" checked>@else<span class="text-muted">—</span>@endif</td><td>{{ $product->name }}</td><td>{{ $product->planning_location ?? 'Global' }}</td><td>{{ data_get($product, 'supplier.name', 'N/A') }}</td><td>{{ number_format($current, 6) }}</td><td>{{ $product->reorder_level }}</td><td>{{ number_format($target, 6) }}</td>@if($forecastHorizon)<td>{{ number_format((float) $product->forecast_quantity, 3) }}<br><small class="text-muted">{{ number_format((float) $product->forecast_confidence_low, 3) }}–{{ number_format((float) $product->forecast_confidence_high, 3) }}</small></td>@endif<td>@if($product->supplier_id)<input type="number" name="quantity[{{ $product->id }}]" class="form-control form-control-sm" value="{{ number_format($suggested, 6, '.', '') }}" min="0.000001" step="0.000001" style="width:130px">@else{{ number_format($suggested, 6) }}@endif</td><td>{{ $product->purchase_price ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="{{ $forecastHorizon ? 10 : 9 }}" class="text-center">No purchase suggestions found.</td></tr>
        @endforelse
        </tbody></table></div><button class="btn btn-primary" type="submit" @disabled($suggestions->isEmpty())>Create purchase order(s)</button>{{ $suggestions->links() }}</div></div>
    </form>
</div></div>
@endsection
