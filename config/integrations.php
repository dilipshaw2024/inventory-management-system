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
    'carrier_tracking_http_endpoint' => env('ERP_CARRIER_TRACKING_HTTP_ENDPOINT'),
    'carrier_tracking_http_token' => env('ERP_CARRIER_TRACKING_HTTP_TOKEN'),
    'carrier_tracking_http_timeout' => (int) env('ERP_CARRIER_TRACKING_HTTP_TIMEOUT', 30),
    'carrier_tracking_http_retries' => (int) env('ERP_CARRIER_TRACKING_HTTP_RETRIES', 2),
    'carrier_tracking_http_retry_sleep' => (int) env('ERP_CARRIER_TRACKING_HTTP_RETRY_SLEEP', 0),
    'bank_statement_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_BANK_STATEMENT_ADAPTERS', ''))
    ))),
    'bank_statement_http_endpoint' => env('ERP_BANK_STATEMENT_HTTP_ENDPOINT'),
    'bank_statement_http_token' => env('ERP_BANK_STATEMENT_HTTP_TOKEN'),
    'bank_statement_http_timeout' => (int) env('ERP_BANK_STATEMENT_HTTP_TIMEOUT', 30),
    'bank_statement_http_retries' => (int) env('ERP_BANK_STATEMENT_HTTP_RETRIES', 2),
    'bank_statement_http_retry_sleep' => (int) env('ERP_BANK_STATEMENT_HTTP_RETRY_SLEEP', 0),
    'e_invoice_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_E_INVOICE_ADAPTERS', ''))
    ))),
    'e_invoice_http_endpoint' => env('ERP_E_INVOICE_HTTP_ENDPOINT'),
    'e_invoice_http_token' => env('ERP_E_INVOICE_HTTP_TOKEN'),
    'e_invoice_http_timeout' => (int) env('ERP_E_INVOICE_HTTP_TIMEOUT', 30),
    'e_invoice_http_retries' => (int) env('ERP_E_INVOICE_HTTP_RETRIES', 2),
    'e_invoice_http_retry_sleep' => (int) env('ERP_E_INVOICE_HTTP_RETRY_SLEEP', 0),
    'e_invoice_callback_secret' => env('ERP_E_INVOICE_CALLBACK_SECRET'),
    'tax_filing_http_endpoint' => env('ERP_TAX_FILING_HTTP_ENDPOINT'),
    'tax_filing_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_TAX_FILING_ADAPTERS', ''))
    ))),
    'tax_filing_http_token' => env('ERP_TAX_FILING_HTTP_TOKEN'),
    'tax_filing_http_timeout' => (int) env('ERP_TAX_FILING_HTTP_TIMEOUT', 30),
    'tax_filing_http_retries' => (int) env('ERP_TAX_FILING_HTTP_RETRIES', 2),
    'tax_filing_http_retry_sleep' => (int) env('ERP_TAX_FILING_HTTP_RETRY_SLEEP', 0),
    'statutory_remittance_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_STATUTORY_REMITTANCE_ADAPTERS', ''))
    ))),
    'statutory_remittance_http_endpoint' => env('ERP_STATUTORY_REMITTANCE_HTTP_ENDPOINT'),
    'statutory_remittance_http_token' => env('ERP_STATUTORY_REMITTANCE_HTTP_TOKEN'),
    'statutory_remittance_http_timeout' => (int) env('ERP_STATUTORY_REMITTANCE_HTTP_TIMEOUT', 30),
    'statutory_remittance_http_retries' => (int) env('ERP_STATUTORY_REMITTANCE_HTTP_RETRIES', 2),
    'statutory_remittance_http_retry_sleep' => (int) env('ERP_STATUTORY_REMITTANCE_HTTP_RETRY_SLEEP', 0),
    'exchange_rate_adapters' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_EXCHANGE_RATE_ADAPTERS', ''))
    ))),
    'exchange_rate_http_endpoint' => env('ERP_EXCHANGE_RATE_HTTP_ENDPOINT'),
    'exchange_rate_http_token' => env('ERP_EXCHANGE_RATE_HTTP_TOKEN'),
    'exchange_rate_http_timeout' => (int) env('ERP_EXCHANGE_RATE_HTTP_TIMEOUT', 30),
    'regional_compliance_packs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERP_REGIONAL_COMPLIANCE_PACKS', ''))
    ))),
];
