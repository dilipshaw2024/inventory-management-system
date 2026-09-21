@extends('admin.admin_master')

@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="inventory-hero mb-4">
            <div>
                <span class="inventory-eyebrow">OPERATIONS CENTER</span>
                <h1 class="inventory-hero-title">Good day, {{ Auth::user()->name ?? 'Administrator' }}</h1>
                <p class="inventory-hero-copy mb-0">Monitor purchasing, inventory, sales, and customer collections from one workspace.</p>
            </div>
            <div class="inventory-hero-actions">
                <a href="{{ route('purchase.add') }}" class="btn btn-light"><i class="ri-add-line me-1"></i> New purchase</a>
                <a href="{{ route('invoice.add') }}" class="btn btn-primary"><i class="ri-shopping-bag-3-line me-1"></i> New invoice</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            @foreach($metrics as $metric)
            <div class="col-xl-3 col-md-6">
                <div class="card inventory-metric-card inventory-metric-{{ $metric[4] }} h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><span class="inventory-metric-label">{{ $metric[0] }}</span><h3 class="inventory-metric-value">{{ $metric[1] }}</h3><span class="inventory-metric-note">{{ $metric[2] }}</span></div>
                            <span class="inventory-metric-icon"><i class="{{ $metric[3] }}"></i></span>
                        </div>
                        <div class="inventory-metric-line"><span style="width: {{ $metric[5] }}"></span></div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-7"><div class="card h-100"><div class="card-body">
                <div class="inventory-section-heading"><div><span class="inventory-eyebrow text-primary">SALES TREND</span><h4 class="card-title mb-0">Approved sales by month</h4></div><span class="inventory-section-caption">Last {{ count($salesTrend) }} months</span></div>
                <div class="d-flex align-items-end gap-2 mt-4" style="height: 150px">
                    @php($maxTrend = max(1, collect($salesTrend)->max('sales')))
                    @foreach($salesTrend as $trend)
                        <div class="text-center flex-fill" title="{{ number_format($trend['sales'], 2) }}"><div class="bg-primary rounded-top" style="height: {{ max(4, round(($trend['sales'] / $maxTrend) * 120)) }}px"></div><small class="text-muted d-block mt-2">{{ substr($trend['month'], 5) }}</small></div>
                    @endforeach
                </div>
            </div></div></div>
            <div class="col-xl-5"><div class="card h-100"><div class="card-body">
                <div class="inventory-section-heading"><div><span class="inventory-eyebrow text-warning">EXCEPTIONS</span><h4 class="card-title mb-0">Inventory attention</h4></div><a href="{{ route('planning.dashboard') }}" class="small">View planning</a></div>
                <div class="d-flex justify-content-between border-bottom py-2"><span>Low stock</span><strong>{{ count($exceptionDrilldowns['low_stock'] ?? []) }}</strong></div>
                @foreach(array_slice($exceptionDrilldowns['low_stock'] ?? [], 0, 3) as $exception)
                    <div class="d-flex justify-content-between py-1 small"><span class="text-truncate me-2">{{ $exception['name'] }}</span><span class="text-danger">{{ number_format($exception['available_quantity'], 2) }}</span></div>
                @endforeach
                <div class="d-flex justify-content-between border-top border-bottom py-2 mt-2"><span>Excess stock</span><strong>{{ count($exceptionDrilldowns['excess_stock'] ?? []) }}</strong></div>
                @foreach(array_slice($exceptionDrilldowns['excess_stock'] ?? [], 0, 3) as $exception)
                    <div class="d-flex justify-content-between py-1 small"><span class="text-truncate me-2">{{ $exception['name'] }}</span><span class="text-warning">{{ number_format($exception['excess_quantity'], 2) }}</span></div>
                @endforeach
            </div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card h-100"><div class="card-body">
                    <div class="inventory-section-heading"><div><span class="inventory-eyebrow text-primary">QUICK ACCESS</span><h4 class="card-title mb-0">Common workflows</h4></div><span class="inventory-section-caption">Move work forward faster</span></div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6"><a href="{{ route('stock.report') }}" class="inventory-quick-link"><span class="inventory-quick-icon bg-soft-primary"><i class="ri-stack-line"></i></span><span><strong>Review stock</strong><small>Check quantities and stock value</small></span><i class="ri-arrow-right-line ms-auto"></i></a></div>
                        <div class="col-md-6"><a href="{{ route('purchase.pending') }}" class="inventory-quick-link"><span class="inventory-quick-icon bg-soft-warning"><i class="ri-inbox-unarchive-line"></i></span><span><strong>Approve purchases</strong><small>Validate incoming inventory</small></span><i class="ri-arrow-right-line ms-auto"></i></a></div>
                        <div class="col-md-6"><a href="{{ route('invoice.pending.list') }}" class="inventory-quick-link"><span class="inventory-quick-icon bg-soft-success"><i class="ri-checkbox-multiple-line"></i></span><span><strong>Approve invoices</strong><small>Complete sales processing</small></span><i class="ri-arrow-right-line ms-auto"></i></a></div>
                        <div class="col-md-6"><a href="{{ route('credit.customer') }}" class="inventory-quick-link"><span class="inventory-quick-icon bg-soft-danger"><i class="ri-bank-card-line"></i></span><span><strong>Collect receivables</strong><small>Review customer credit</small></span><i class="ri-arrow-right-line ms-auto"></i></a></div>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-4"><div class="card inventory-readiness-card h-100"><div class="card-body">
                <span class="inventory-eyebrow">WORKSPACE STATUS</span><h4 class="mt-2 mb-3">Your workspace is ready</h4>
                <div class="inventory-readiness-item"><i class="ri-checkbox-circle-fill"></i><span>Catalog and pricing</span><strong>Ready</strong></div>
                <div class="inventory-readiness-item"><i class="ri-checkbox-circle-fill"></i><span>Purchasing workflow</span><strong>Ready</strong></div>
                <div class="inventory-readiness-item"><i class="ri-checkbox-circle-fill"></i><span>Sales and payments</span><strong>Ready</strong></div>
                <a href="{{ route('admin.profile') }}" class="btn btn-outline-primary w-100 mt-3">Manage account <i class="ri-arrow-right-line ms-1"></i></a>
            </div></div></div>
        </div>
    </div>
</div>
@endsection
