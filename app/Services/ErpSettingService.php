<?php

namespace App\Services;

use App\Models\CompanyErpSetting;

class ErpSettingService
{
    public function get(string $key, mixed $default = null, ?int $companyId = null): mixed
    {
        $companyId ??= auth()->user()?->company_id;
        if (!$companyId) return $default;
        $setting = CompanyErpSetting::where('company_id', $companyId)->where('key', $key)->first();
        if (!$setting) return $default;
        return match ($setting->value_type) {
            'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $setting->value,
            'float' => (float) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public function put(string $key, mixed $value, string $type = 'string', ?int $companyId = null): CompanyErpSetting
    {
        $companyId ??= auth()->user()?->company_id;
        if (!$companyId) throw new \RuntimeException('A company context is required to save ERP settings.');
        $stored = $type === 'json' ? json_encode($value, JSON_THROW_ON_ERROR) : ($type === 'bool' ? ($value ? '1' : '0') : (string) $value);
        return CompanyErpSetting::updateOrCreate(['company_id' => $companyId, 'key' => $key], ['value' => $stored, 'value_type' => $type]);
    }
}
