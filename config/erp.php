<?php

return [
    'qr_code_binary' => env('ERP_QR_CODE_BINARY', 'qrencode'),
    'dashboard_cache_ttl' => max(0, (int) env('ERP_DASHBOARD_CACHE_TTL', 60)),
    'password_policy' => [
        'min_length' => max(8, (int) env('ERP_PASSWORD_MIN_LENGTH', 12)),
        'mixed_case' => filter_var(env('ERP_PASSWORD_MIXED_CASE', true), FILTER_VALIDATE_BOOLEAN),
        'numbers' => filter_var(env('ERP_PASSWORD_NUMBERS', true), FILTER_VALIDATE_BOOLEAN),
        'symbols' => filter_var(env('ERP_PASSWORD_SYMBOLS', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
