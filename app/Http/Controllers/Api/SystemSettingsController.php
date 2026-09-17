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
        'abc_a_threshold_percent', 'abc_b_threshold_percent', 'allow_expired_batch_issue',
        'allow_past_best_before_issue', 'warehouse_capacity_alert_percent', 'slow_moving_days',
        'dead_stock_days', 'expiry_alert_days',
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
            'max_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'abc_a_threshold_percent' => ['sometimes', 'numeric', 'gt:0', 'lt:100'],
            'abc_b_threshold_percent' => ['sometimes', 'numeric', 'gt:abc_a_threshold_percent', 'lte:100'],
            'warehouse_capacity_alert_percent' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            'slow_moving_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'dead_stock_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'expiry_alert_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'allow_expired_batch_issue' => ['sometimes', 'boolean'],
            'allow_past_best_before_issue' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('abc_b_threshold_percent', $data) && !array_key_exists('abc_a_threshold_percent', $data)) {
            $data['abc_a_threshold_percent'] = app(ErpSettingService::class)->get('abc_a_threshold_percent', 80, (int) $companyId);
            if ((float) $data['abc_b_threshold_percent'] <= (float) $data['abc_a_threshold_percent']) return response()->json(['message' => 'ABC B threshold must be greater than the current ABC A threshold.'], 422);
        }
        $service = app(ErpSettingService::class);
        $before = $this->values((int) $companyId);
        foreach ($data as $key => $value) {
            $type = in_array($key, ['allow_expired_batch_issue', 'allow_past_best_before_issue'], true) ? 'bool' : ($key === 'decimal_precision' || in_array($key, ['slow_moving_days', 'dead_stock_days', 'expiry_alert_days'], true) ? 'int' : (in_array($key, ['purchase_price_variance_percent', 'max_discount_percent', 'abc_a_threshold_percent', 'abc_b_threshold_percent', 'warehouse_capacity_alert_percent'], true) ? 'float' : 'string'));
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
            'max_discount_percent' => $service->get('max_discount_percent', 100, $companyId),
            'abc_a_threshold_percent' => $service->get('abc_a_threshold_percent', 80, $companyId),
            'abc_b_threshold_percent' => $service->get('abc_b_threshold_percent', 95, $companyId),
            'warehouse_capacity_alert_percent' => $service->get('warehouse_capacity_alert_percent', 80, $companyId),
            'slow_moving_days' => $service->get('slow_moving_days', 90, $companyId),
            'dead_stock_days' => $service->get('dead_stock_days', 180, $companyId),
            'expiry_alert_days' => $service->get('expiry_alert_days', 90, $companyId),
            'allow_expired_batch_issue' => $service->get('allow_expired_batch_issue', false, $companyId),
            'allow_past_best_before_issue' => $service->get('allow_past_best_before_issue', false, $companyId),
        ];
    }
}
