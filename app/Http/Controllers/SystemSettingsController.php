<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\ErpSettingService;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    public function index()
    {
        $service = app(ErpSettingService::class);
        $settings = [
            'negative_stock_policy' => $service->get('negative_stock_policy', 'block'),
            'date_format' => $service->get('date_format', 'Y-m-d'),
            'decimal_precision' => $service->get('decimal_precision', 2),
            'default_tax_mode' => $service->get('default_tax_mode', 'exclusive'),
            'document_print_size' => $service->get('document_print_size', 'A4'),
            'purchase_price_variance_percent' => $service->get('purchase_price_variance_percent', 0),
            'max_discount_percent' => $service->get('max_discount_percent', 100),
            'abc_a_threshold_percent' => $service->get('abc_a_threshold_percent', 80),
            'abc_b_threshold_percent' => $service->get('abc_b_threshold_percent', 95),
            'warehouse_capacity_alert_percent' => $service->get('warehouse_capacity_alert_percent', 80),
            'slow_moving_days' => $service->get('slow_moving_days', 90),
            'dead_stock_days' => $service->get('dead_stock_days', 180),
            'expiry_alert_days' => $service->get('expiry_alert_days', 90),
            'allow_expired_batch_issue' => $service->get('allow_expired_batch_issue', false),
            'allow_past_best_before_issue' => $service->get('allow_past_best_before_issue', false),
            'planning_calendar' => $service->get('planning_calendar', ['weekend_days' => [0, 6], 'holidays' => []]),
        ];
        return view('admin.erp.system_settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'negative_stock_policy' => ['required', 'in:block,approval,allow'],
            'date_format' => ['required', 'in:Y-m-d,d-m-Y,m/d/Y'],
            'decimal_precision' => ['required', 'integer', 'min:0', 'max:6'],
            'default_tax_mode' => ['required', 'in:exclusive,inclusive'],
            'document_print_size' => ['required', 'in:A4,Letter'],
            'purchase_price_variance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'abc_a_threshold_percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'abc_b_threshold_percent' => ['required', 'numeric', 'gt:abc_a_threshold_percent', 'lte:100'],
            'warehouse_capacity_alert_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'slow_moving_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'dead_stock_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'expiry_alert_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'allow_expired_batch_issue' => ['sometimes', 'boolean'],
            'allow_past_best_before_issue' => ['sometimes', 'boolean'],
            'planning_weekend_days' => ['required', 'string', 'max:20', 'regex:/^[0-6](,[0-6])*$/'],
            'planning_holidays' => ['nullable', 'string', 'max:5000', 'regex:/^(\d{4}-\d{2}-\d{2})(,\d{4}-\d{2}-\d{2})*$/'],
        ]);
        $service = app(ErpSettingService::class);
        $calendar = ['weekend_days' => array_map('intval', explode(',', $data['planning_weekend_days'])), 'holidays' => $data['planning_holidays'] === '' ? [] : array_values(array_unique(explode(',', $data['planning_holidays'])))];
        unset($data['planning_weekend_days'], $data['planning_holidays']);
        foreach ($data as $key => $value) $service->put($key, $value, in_array($key, ['allow_expired_batch_issue', 'allow_past_best_before_issue'], true) ? 'bool' : ($key === 'decimal_precision' || in_array($key, ['slow_moving_days', 'dead_stock_days', 'expiry_alert_days'], true) ? 'int' : (in_array($key, ['purchase_price_variance_percent', 'max_discount_percent', 'abc_a_threshold_percent', 'abc_b_threshold_percent', 'warehouse_capacity_alert_percent'], true) ? 'float' : 'string')));
        $service->put('planning_calendar', $calendar, 'json');
        app(AuditService::class)->record('erp_settings.updated', auth()->user(), null, $data);
        return back()->with(['message' => 'ERP settings updated.', 'alert-type' => 'success']);
    }

    public function updateExpiredBatchPolicy(Request $request)
    {
        $data = $request->validate(['allow_expired_batch_issue' => ['required', 'boolean'], 'allow_past_best_before_issue' => ['required', 'boolean']]);
        $service = app(ErpSettingService::class);
        $service->put('allow_expired_batch_issue', $data['allow_expired_batch_issue'], 'bool');
        $service->put('allow_past_best_before_issue', $data['allow_past_best_before_issue'], 'bool');
        app(AuditService::class)->record('erp_settings.expired_batch_policy_updated', auth()->user(), null, $data);
        return back()->with(['message' => 'Expired-batch issue policy updated.', 'alert-type' => 'success']);
    }
}
