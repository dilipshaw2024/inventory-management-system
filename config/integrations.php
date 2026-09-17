<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ERP integration adapters
    |--------------------------------------------------------------------------
    |
    | Register application-specific provider adapter classes here. Each class
    | must implement the relevant adapter contract and is resolved by Laravel's
    | container at boot time.
    |
    */
    'carrier_tracking_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_CARRIER_TRACKING_ADAPTERS', ''))
    ))),
    'bank_statement_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_BANK_STATEMENT_ADAPTERS', ''))
    ))),
];
