@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box"><h4>Replenishment Scenario Planning</h4><p class="text-muted">Read-only what-if projections. No stock, purchase order, or transfer records are created.</p></div>
    <div class="card mb-3"><div class="card-body"><form class="row g-2 align-items-end">
        <div class="col-md-2"><label>Horizon days</label><input name="horizon_days" type="number" min="1" max="365" value="{{ $horizonDays }}" class="form-control"></div>
        <div class="col-md-2"><label>Demand multiplier</label><input name="demand_multiplier" type="number" min="0" max="10" step="0.01" value="{{ $demandMultiplier }}" class="form-control"></div>
        <div class="col-md-2"><label>Daily demand override</label><input name="daily_demand" type="number" min="0" step="0.001" value="{{ $dailyDemand === null ? '' : $dailyDemand }}" class="form-control"></div>
        <div class="col-md-3"><label>Product</label><select name="product_id" class="form-select"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) $productId === (string) $product->id)>{{ $product->name }}{{ $product->sku ? ' / '.$product->sku : '' }}</option>@endforeach</select></div>
        <div class="col-md-3"><label>Location</label><select name="location_id" class="form-select"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) $locationId === (string) $location->id)>{{ $location->warehouse->name }} / {{ $location->code }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-primary">Simulate</button></div>
    </form><hr><form method="POST" action="{{ route('planning.replenishment.scenario.save') }}" class="row g-2 align-items-end">@csrf
        <div class="col-md-4"><label>Save this scenario</label><input name="name" class="form-control" placeholder="Scenario name" required maxlength="150"></div>
        <input type="hidden" name="horizon_days" value="{{ $horizonDays }}"><input type="hidden" name="demand_multiplier" value="{{ $demandMultiplier }}"><input type="hidden" name="product_id" value="{{ $productId }}"><input type="hidden" name="location_id" value="{{ $locationId }}">@if($dailyDemand !== null)<input type="hidden" name="daily_demand" value="{{ $dailyDemand }}">@endif
        <div class="col-md-2"><button class="btn btn-outline-success">Save snapshot</button></div>
    </form></div></div>
    @if($savedScenarios->isNotEmpty())<div class="card mb-3"><div class="card-body"><h5>Saved scenario snapshots</h5><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Name</th><th>Scope</th><th>Horizon</th><th>Demand</th><th>Saved</th></tr></thead><tbody>@foreach($savedScenarios as $saved)<tr><td>{{ $saved->name }}</td><td>{{ $saved->product?->name ?? 'All products' }} / {{ $saved->location?->code ?? 'All locations' }}</td><td>{{ $saved->horizon_days }} days</td><td>{{ $saved->daily_demand_override !== null ? 'Override '.$saved->daily_demand_override : '× '.$saved->demand_multiplier }}</td><td>{{ $saved->updated_at->format('Y-m-d H:i') }}</td></tr>@endforeach</tbody></table></div></div></div>@endif
    <div class="card"><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Location</th><th>Baseline/day</th><th>Scenario/day</th><th>First projected balance</th><th>First shortfall</th><th>Status</th></tr></thead><tbody>
    @forelse($scenarios as $row)
        @php($first = $row['buckets']->first())
        <tr><td>{{ $row['product_id'] }}</td><td>{{ $row['location_id'] ?: 'All' }}</td><td>{{ number_format($row['baseline_daily_demand'], 3) }}</td><td>{{ number_format($row['scenario_daily_demand'], 3) }}</td><td colspan="3"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Receipts</th><th>Scenario balance</th><th>Shortfall</th><th>Status</th></tr></thead><tbody>@foreach($row['buckets'] as $bucket)<tr><td>{{ $bucket['date'] }}</td><td>{{ number_format($bucket['planned_receipt'] + $bucket['open_purchase_receipt'] + $bucket['open_transfer_receipt'], 3) }}</td><td>{{ number_format($bucket['scenario_projected_balance'], 3) }}</td><td>{{ number_format($bucket['scenario_shortfall'], 3) }}</td><td>{{ $bucket['scenario_status'] }}</td></tr>@endforeach</tbody></table></div></td></tr>
    @empty
        <tr><td colspan="7" class="text-center">No replenishment proposal is available for the selected scope.</td></tr>
    @endforelse
    </tbody></table></div></div>
</div></div>
@endsection
