@extends('admin.admin_master')

@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box">
            <h4>ERP System Settings</h4>
            <p class="text-muted">These defaults apply to the current company. Negative-stock blocking remains the default.</p>
        </div>
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('erp.settings.update') }}">
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-4"><label>Negative stock policy</label><select name="negative_stock_policy" class="form-select"><option value="block" @selected($settings['negative_stock_policy'] === 'block')>Block outbound movement</option><option value="approval" @selected($settings['negative_stock_policy'] === 'approval')>Allow only independently approved documents</option><option value="allow" @selected($settings['negative_stock_policy'] === 'allow')>Allow outbound movement</option></select></div>
                        <div class="col-md-4"><label>Date format</label><select name="date_format" class="form-select">@foreach(['Y-m-d','d-m-Y','m/d/Y'] as $format)<option value="{{ $format }}" @selected($settings['date_format'] === $format)>{{ $format }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label>Decimal precision</label><input name="decimal_precision" type="number" min="0" max="6" value="{{ $settings['decimal_precision'] }}" class="form-control"></div>
                        <div class="col-md-4"><label>Default tax mode</label><select name="default_tax_mode" class="form-select"><option value="exclusive" @selected($settings['default_tax_mode'] === 'exclusive')>Exclusive</option><option value="inclusive" @selected($settings['default_tax_mode'] === 'inclusive')>Inclusive</option></select></div>
                        <div class="col-md-4"><label>Document print size</label><select name="document_print_size" class="form-select"><option value="A4" @selected($settings['document_print_size'] === 'A4')>A4</option><option value="Letter" @selected($settings['document_print_size'] === 'Letter')>Letter</option></select></div>
                        <div class="col-md-4"><label>Purchase price variance tolerance (%)</label><input name="purchase_price_variance_percent" type="number" min="0" max="100" step="0.01" value="{{ $settings['purchase_price_variance_percent'] }}" class="form-control"><small class="text-muted">0 disables price variance blocking.</small></div>
                        <div class="col-md-4"><label>Maximum sales discount (%)</label><input name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="{{ $settings['max_discount_percent'] }}" class="form-control"><small class="text-muted">Higher discounts require the sales discount override permission.</small></div>
                        <div class="col-md-4"><label>ABC class A threshold (%)</label><input name="abc_a_threshold_percent" type="number" min="0.01" max="99.99" step="0.01" value="{{ $settings['abc_a_threshold_percent'] }}" class="form-control"><small class="text-muted">Cumulative contribution cutoff for class A.</small></div>
                        <div class="col-md-4"><label>ABC class B threshold (%)</label><input name="abc_b_threshold_percent" type="number" min="0.02" max="100" step="0.01" value="{{ $settings['abc_b_threshold_percent'] }}" class="form-control"><small class="text-muted">Must be greater than the class A threshold.</small></div>
                        <div class="col-md-4"><label>Warehouse capacity alert (%)</label><input name="warehouse_capacity_alert_percent" type="number" min="0.01" max="100" step="0.01" value="{{ $settings['warehouse_capacity_alert_percent'] }}" class="form-control"><small class="text-muted">Locations at or above this utilization are flagged as warning.</small></div>
                        <div class="col-md-4"><label>Slow-moving window (days)</label><input name="slow_moving_days" type="number" min="1" max="3650" value="{{ $settings['slow_moving_days'] }}" class="form-control"></div>
                        <div class="col-md-4"><label>Dead-stock window (days)</label><input name="dead_stock_days" type="number" min="1" max="3650" value="{{ $settings['dead_stock_days'] }}" class="form-control"></div>
                        <div class="col-md-4"><label>Expiry alert window (days)</label><input name="expiry_alert_days" type="number" min="1" max="3650" value="{{ $settings['expiry_alert_days'] }}" class="form-control"></div>
                        <div class="col-md-6"><label>Planning weekend days</label><input name="planning_weekend_days" type="text" value="{{ implode(',', $settings['planning_calendar']['weekend_days'] ?? [0,6]) }}" class="form-control"><small class="text-muted">ISO weekday numbers: 0 Sunday through 6 Saturday.</small></div>
                        <div class="col-md-6"><label>Planning holidays</label><input name="planning_holidays" type="text" value="{{ implode(',', $settings['planning_calendar']['holidays'] ?? []) }}" class="form-control"><small class="text-muted">Comma-separated dates in YYYY-MM-DD format.</small></div>
                        <div class="col-12"><button class="btn btn-primary">Save settings</button></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@include('admin.erp.expired_batch_policy')
@endsection
