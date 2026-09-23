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
            'stock_count_recount_variance_percent' => $service->get('stock_count_recount_variance_percent', 0),
            'max_discount_percent' => $service->get('max_discount_percent', 100),
            'abc_a_threshold_percent' => $service->get('abc_a_threshold_percent', 80),
            'abc_b_threshold_percent' => $service->get('abc_b_threshold_percent', 95),
            'warehouse_capacity_alert_percent' => $service->get('warehouse_capacity_alert_percent', 80),
            'slow_moving_days' => $service->get('slow_moving_days', 90),
            'dead_stock_days' => $service->get('dead_stock_days', 180),
            'expiry_alert_days' => $service->get('expiry_alert_days', 90),
            'reservation_expiry_days' => $service->get('reservation_expiry_days', 0),
            'purchase_over_receipt_tolerance_percent' => $service->get('purchase_over_receipt_tolerance_percent', 0),
            'auto_release_production_orders' => $service->get('auto_release_production_orders', false),
            'allow_expired_batch_issue' => $service->get('allow_expired_batch_issue', false),
            'allow_past_best_before_issue' => $service->get('allow_past_best_before_issue', false),
            'planning_calendar' => $service->get('planning_calendar', ['weekend_days' => [0, 6], 'holidays' => []]),
            'carrier_sla_hours' => $service->get('carrier_sla_hours', []),
            'branch_carrier_sla_hours' => $service->get('branch_carrier_sla_hours', []),
            'sla_calendar' => $service->get('sla_calendar', []),
            'branch_sla_calendars' => $service->get('branch_sla_calendars', []),
            'default_inventory_costing_method' => $service->get('default_inventory_costing_method', 'fifo'),
            'default_standard_cost' => $service->get('default_standard_cost', null),
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
            'stock_count_recount_variance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'abc_a_threshold_percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'abc_b_threshold_percent' => ['required', 'numeric', 'gt:abc_a_threshold_percent', 'lte:100'],
            'warehouse_capacity_alert_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'slow_moving_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'dead_stock_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'expiry_alert_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'reservation_expiry_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'purchase_over_receipt_tolerance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'allow_expired_batch_issue' => ['sometimes', 'boolean'],
            'allow_past_best_before_issue' => ['sometimes', 'boolean'],
            'auto_release_production_orders' => ['sometimes', 'boolean'],
            'planning_weekend_days' => ['required', 'string', 'max:20', 'regex:/^[0-6](,[0-6])*$/'],
            'planning_holidays' => ['nullable', 'string', 'max:5000', 'regex:/^(\d{4}-\d{2}-\d{2})(,\d{4}-\d{2}-\d{2})*$/'],
            'carrier_sla_hours' => ['nullable', 'string', 'max:10000', 'json'],
            'branch_carrier_sla_hours' => ['nullable', 'string', 'max:20000', 'json'],
            'sla_calendar' => ['nullable', 'string', 'max:10000', 'json'],
            'branch_sla_calendars' => ['nullable', 'string', 'max:20000', 'json'],
            'default_inventory_costing_method' => ['required', 'in:fifo,weighted_average,moving_average,standard'],
            'default_standard_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $service = app(ErpSettingService::class);
        $calendar = ['weekend_days' => array_map('intval', explode(',', $data['planning_weekend_days'])), 'holidays' => $data['planning_holidays'] === '' ? [] : array_values(array_unique(explode(',', $data['planning_holidays'])))];
        unset($data['planning_weekend_days'], $data['planning_holidays']);
        if (array_key_exists('carrier_sla_hours', $data)) {
            $decoded = json_decode($data['carrier_sla_hours'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_keys($decoded) !== array_filter(array_keys($decoded), 'is_string')) return back()->withErrors(['carrier_sla_hours' => 'Carrier SLA policy must be a JSON object.'])->withInput();
            foreach ($decoded as $carrier => $hours) if (trim((string) $carrier) === '' || !is_numeric($hours) || (float) $hours < 1 || (float) $hours > 8760) return back()->withErrors(['carrier_sla_hours' => 'Each carrier SLA must be between 1 and 8760 hours.'])->withInput();
            $data['carrier_sla_hours'] = array_combine(array_map(fn ($carrier) => trim((string) $carrier), array_keys($decoded)), array_map('floatval', array_values($decoded)));
        }
        if (array_key_exists('branch_carrier_sla_hours', $data)) {
            $decoded = json_decode($data['branch_carrier_sla_hours'], true, 512, JSON_THROW_ON_ERROR);
            $branchIds = array_map('intval', array_keys(is_array($decoded) ? $decoded : []));
            if (!is_array($decoded) || array_keys($decoded) !== array_filter(array_keys($decoded), 'is_string') || (count($branchIds) > 0 && \App\Models\Branch::where('company_id', auth()->user()?->company_id)->whereIn('id', $branchIds)->count() !== count(array_unique($branchIds)))) return back()->withErrors(['branch_carrier_sla_hours' => 'Branch carrier SLA policy must be a JSON object containing only current-company branch IDs.'])->withInput();
            foreach ($decoded as $branchId => $carriers) {
                if (!is_array($carriers)) return back()->withErrors(['branch_carrier_sla_hours' => 'Each branch SLA value must be a carrier-to-hours object.'])->withInput();
                foreach ($carriers as $carrier => $hours) if (trim((string) $carrier) === '' || !is_numeric($hours) || (float) $hours < 1 || (float) $hours > 8760) return back()->withErrors(['branch_carrier_sla_hours' => 'Each branch carrier SLA must be between 1 and 8760 hours.'])->withInput();
                $decoded[$branchId] = array_combine(array_map(fn ($carrier) => trim((string) $carrier), array_keys($carriers)), array_map('floatval', array_values($carriers)));
            }
            $data['branch_carrier_sla_hours'] = $decoded;
        }
        foreach (['sla_calendar', 'branch_sla_calendars'] as $calendarKey) if (array_key_exists($calendarKey, $data)) {
            $decoded = json_decode($data[$calendarKey], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) return back()->withErrors([$calendarKey => 'SLA calendar settings must be a JSON object.'])->withInput();
            $calendars = $calendarKey === 'sla_calendar' ? [null => $decoded] : $decoded;
            foreach ($calendars as $branchId => $calendar) {
                if ($calendarKey === 'branch_sla_calendars' && (!is_numeric($branchId) || !\App\Models\Branch::where('company_id', auth()->user()?->company_id)->whereKey((int) $branchId)->exists())) return back()->withErrors([$calendarKey => 'Branch SLA calendars may only reference current-company branch IDs.'])->withInput();
                if (!is_array($calendar) || (isset($calendar['weekend_days']) && (!is_array($calendar['weekend_days']) || collect($calendar['weekend_days'])->contains(fn ($day): bool => !is_numeric($day) || (int) $day < 0 || (int) $day > 6))) || (isset($calendar['holidays']) && (!is_array($calendar['holidays']) || collect($calendar['holidays'])->contains(fn ($day): bool => !is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1)))) return back()->withErrors([$calendarKey => 'SLA calendar weekdays and holidays are invalid.'])->withInput();
                foreach (['shift_start', 'shift_end'] as $shift) if (isset($calendar[$shift]) && $calendar[$shift] !== '24:00' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $calendar[$shift]) !== 1) return back()->withErrors([$calendarKey => 'SLA calendar shifts must use HH:MM format.'])->withInput();
            }
            $data[$calendarKey] = $decoded;
        }
        foreach ($data as $key => $value) $service->put($key, $value, in_array($key, ['carrier_sla_hours', 'branch_carrier_sla_hours', 'sla_calendar', 'branch_sla_calendars'], true) ? 'json' : (in_array($key, ['allow_expired_batch_issue', 'allow_past_best_before_issue', 'auto_release_production_orders'], true) ? 'bool' : ($key === 'decimal_precision' || in_array($key, ['slow_moving_days', 'dead_stock_days', 'expiry_alert_days', 'reservation_expiry_days'], true) ? 'int' : (in_array($key, ['purchase_price_variance_percent', 'purchase_over_receipt_tolerance_percent', 'stock_count_recount_variance_percent', 'max_discount_percent', 'abc_a_threshold_percent', 'abc_b_threshold_percent', 'warehouse_capacity_alert_percent', 'default_standard_cost'], true) ? 'float' : 'string'))));
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
