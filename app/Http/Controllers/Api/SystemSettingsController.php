<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\ErpSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    private const KEYS = [
        'negative_stock_policy', 'date_format', 'decimal_precision', 'default_tax_mode',
        'document_print_size', 'purchase_price_variance_percent', 'max_discount_percent',
        'stock_count_recount_variance_percent',
        'abc_a_threshold_percent', 'abc_b_threshold_percent', 'allow_expired_batch_issue',
        'allow_past_best_before_issue', 'warehouse_capacity_alert_percent', 'slow_moving_days',
        'dead_stock_days', 'expiry_alert_days',
        'reservation_expiry_days',
        'purchase_over_receipt_tolerance_percent',
        'auto_release_production_orders',
        'carrier_sla_hours',
        'branch_carrier_sla_hours',
    ];

    public function show(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for ERP settings.');
        return response()->json(['data' => $this->values((int) $companyId)]);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for ERP settings.');
        $data = $request->validate([
            'negative_stock_policy' => ['sometimes', 'in:block,approval,allow'],
            'date_format' => ['sometimes', 'in:Y-m-d,d-m-Y,m/d/Y'],
            'decimal_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'default_tax_mode' => ['sometimes', 'in:exclusive,inclusive'],
            'document_print_size' => ['sometimes', 'in:A4,Letter'],
            'purchase_price_variance_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'stock_count_recount_variance_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'abc_a_threshold_percent' => ['sometimes', 'numeric', 'gt:0', 'lt:100'],
            'abc_b_threshold_percent' => ['sometimes', 'numeric', 'gt:abc_a_threshold_percent', 'lte:100'],
            'warehouse_capacity_alert_percent' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            'slow_moving_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'dead_stock_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'expiry_alert_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'reservation_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'purchase_over_receipt_tolerance_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'allow_expired_batch_issue' => ['sometimes', 'boolean'],
            'allow_past_best_before_issue' => ['sometimes', 'boolean'],
            'auto_release_production_orders' => ['sometimes', 'boolean'],
            'carrier_sla_hours' => ['sometimes', 'array'],
            'carrier_sla_hours.*' => ['numeric', 'min:1', 'max:8760'],
            'branch_carrier_sla_hours' => ['sometimes', 'array'],
            'branch_carrier_sla_hours.*' => ['array'],
            'branch_carrier_sla_hours.*.*' => ['numeric', 'min:1', 'max:8760'],
            'sla_calendar' => ['sometimes', 'array'],
            'branch_sla_calendars' => ['sometimes', 'array'],
            'branch_sla_calendars.*' => ['array'],
        ]);
        if (array_key_exists('abc_b_threshold_percent', $data) && !array_key_exists('abc_a_threshold_percent', $data)) {
            $data['abc_a_threshold_percent'] = app(ErpSettingService::class)->get('abc_a_threshold_percent', 80, (int) $companyId);
            if ((float) $data['abc_b_threshold_percent'] <= (float) $data['abc_a_threshold_percent']) return response()->json(['message' => 'ABC B threshold must be greater than the current ABC A threshold.'], 422);
        }
        if (array_key_exists('branch_carrier_sla_hours', $data)) {
            $branchIds = array_map('intval', array_keys($data['branch_carrier_sla_hours']));
            if (count($branchIds) !== count(array_unique($branchIds)) || \App\Models\Branch::where('company_id', $companyId)->whereIn('id', $branchIds)->count() !== count($branchIds)) return response()->json(['message' => 'Branch carrier SLA policy contains a branch outside the current company.'], 422);
            $data['branch_carrier_sla_hours'] = app(\App\Services\CarrierSlaPolicyService::class)->normalize($data['branch_carrier_sla_hours']);
        }
        if (array_key_exists('sla_calendar', $data)) $this->assertCalendar($data['sla_calendar']);
        if (array_key_exists('branch_sla_calendars', $data)) {
            $branchIds = array_map('intval', array_keys($data['branch_sla_calendars']));
            if (count($branchIds) !== count(array_unique($branchIds)) || \App\Models\Branch::where('company_id', $companyId)->whereIn('id', $branchIds)->count() !== count($branchIds)) return response()->json(['message' => 'Branch SLA calendars contain a branch outside the current company.'], 422);
            foreach ($data['branch_sla_calendars'] as $calendar) $this->assertCalendar($calendar);
        }
        $service = app(ErpSettingService::class);
        $before = $this->values((int) $companyId);
        foreach ($data as $key => $value) {
            $type = in_array($key, ['allow_expired_batch_issue', 'allow_past_best_before_issue', 'auto_release_production_orders'], true) ? 'bool' : (in_array($key, ['carrier_sla_hours', 'branch_carrier_sla_hours', 'sla_calendar', 'branch_sla_calendars'], true) ? 'json' : ($key === 'decimal_precision' || in_array($key, ['slow_moving_days', 'dead_stock_days', 'expiry_alert_days', 'reservation_expiry_days'], true) ? 'int' : (in_array($key, ['purchase_price_variance_percent', 'purchase_over_receipt_tolerance_percent', 'stock_count_recount_variance_percent', 'max_discount_percent', 'abc_a_threshold_percent', 'abc_b_threshold_percent', 'warehouse_capacity_alert_percent'], true) ? 'float' : 'string')));
            $service->put($key, $value, $type, (int) $companyId);
        }
        $after = $this->values((int) $companyId);
        app(AuditService::class)->record('erp_settings.api_updated', $request->user(), $before, $after);
        return response()->json(['data' => $after]);
    }

    private function values(int $companyId): array
    {
        $service = app(ErpSettingService::class);
        return [
            'negative_stock_policy' => $service->get('negative_stock_policy', 'block', $companyId),
            'date_format' => $service->get('date_format', 'Y-m-d', $companyId),
            'decimal_precision' => $service->get('decimal_precision', 2, $companyId),
            'default_tax_mode' => $service->get('default_tax_mode', 'exclusive', $companyId),
            'document_print_size' => $service->get('document_print_size', 'A4', $companyId),
            'purchase_price_variance_percent' => $service->get('purchase_price_variance_percent', 0, $companyId),
            'stock_count_recount_variance_percent' => $service->get('stock_count_recount_variance_percent', 0, $companyId),
            'max_discount_percent' => $service->get('max_discount_percent', 100, $companyId),
            'abc_a_threshold_percent' => $service->get('abc_a_threshold_percent', 80, $companyId),
            'abc_b_threshold_percent' => $service->get('abc_b_threshold_percent', 95, $companyId),
            'warehouse_capacity_alert_percent' => $service->get('warehouse_capacity_alert_percent', 80, $companyId),
            'slow_moving_days' => $service->get('slow_moving_days', 90, $companyId),
            'dead_stock_days' => $service->get('dead_stock_days', 180, $companyId),
            'expiry_alert_days' => $service->get('expiry_alert_days', 90, $companyId),
            'reservation_expiry_days' => $service->get('reservation_expiry_days', 0, $companyId),
            'purchase_over_receipt_tolerance_percent' => $service->get('purchase_over_receipt_tolerance_percent', 0, $companyId),
            'allow_expired_batch_issue' => $service->get('allow_expired_batch_issue', false, $companyId),
            'allow_past_best_before_issue' => $service->get('allow_past_best_before_issue', false, $companyId),
            'auto_release_production_orders' => $service->get('auto_release_production_orders', false, $companyId),
            'carrier_sla_hours' => $service->get('carrier_sla_hours', [], $companyId),
            'branch_carrier_sla_hours' => $service->get('branch_carrier_sla_hours', [], $companyId),
            'sla_calendar' => $service->get('sla_calendar', null, $companyId),
            'branch_sla_calendars' => $service->get('branch_sla_calendars', [], $companyId),
        ];
    }

    private function assertCalendar(mixed $calendar): void
    {
        if (!is_array($calendar)) abort(422, 'SLA calendar must be an object.');
        if (isset($calendar['weekend_days']) && (!is_array($calendar['weekend_days']) || collect($calendar['weekend_days'])->contains(fn ($day): bool => !is_numeric($day) || (int) $day < 0 || (int) $day > 6))) abort(422, 'SLA calendar weekend days must be weekday numbers from 0 through 6.');
        if (isset($calendar['holidays']) && (!is_array($calendar['holidays']) || collect($calendar['holidays'])->contains(fn ($day): bool => !is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1))) abort(422, 'SLA calendar holidays must use YYYY-MM-DD dates.');
        foreach (['shift_start', 'shift_end'] as $key) if (isset($calendar[$key]) && ($calendar[$key] !== '24:00' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $calendar[$key]) !== 1)) abort(422, 'SLA calendar shifts must use HH:MM format.');
    }
}
