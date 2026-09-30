<?php

namespace App\Services;

use App\Models\Store;
use InvalidArgumentException;

class StorePosSettingsService
{
    public const TENDERS = ['cash', 'card', 'bank', 'transfer', 'other'];

    public function normalize(?array $settings): ?array
    {
        if ($settings === null) return null;
        $normalized = $settings;
        if (array_key_exists('allowed_tenders', $normalized)) {
            $tenders = $normalized['allowed_tenders'];
            if (!is_array($tenders) || $tenders === []) throw new InvalidArgumentException('POS allowed_tenders must contain at least one tender.');
            $tenders = array_values(array_unique(array_map('strval', $tenders)));
            if (array_diff($tenders, self::TENDERS)) throw new InvalidArgumentException('POS allowed_tenders contains an unsupported tender.');
            $normalized['allowed_tenders'] = $tenders;
        }
        if (array_key_exists('receipt_footer', $normalized) && $normalized['receipt_footer'] !== null) {
            $normalized['receipt_footer'] = (string) $normalized['receipt_footer'];
            if (strlen($normalized['receipt_footer']) > 500) throw new InvalidArgumentException('POS receipt_footer cannot exceed 500 characters.');
        }
        if (array_key_exists('require_customer', $normalized)) $normalized['require_customer'] = (bool) $normalized['require_customer'];
        if (array_key_exists('auto_print_receipt', $normalized)) $normalized['auto_print_receipt'] = (bool) $normalized['auto_print_receipt'];
        if (array_key_exists('cash_variance_tolerance', $normalized)) {
            if (!is_numeric($normalized['cash_variance_tolerance']) || (float) $normalized['cash_variance_tolerance'] < 0) throw new InvalidArgumentException('POS cash_variance_tolerance must be zero or greater.');
            $normalized['cash_variance_tolerance'] = round((float) $normalized['cash_variance_tolerance'], 6);
        }
        return $normalized;
    }

    public function allowedTenders(?Store $store): array
    {
        $tenders = $store?->pos_settings['allowed_tenders'] ?? self::TENDERS;
        if (!is_array($tenders) || $tenders === []) return self::TENDERS;
        return array_values(array_intersect(array_unique(array_map('strval', $tenders)), self::TENDERS)) ?: self::TENDERS;
    }
}
