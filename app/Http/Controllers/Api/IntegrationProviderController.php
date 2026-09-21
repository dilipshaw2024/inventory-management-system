<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\BankStatementAdapterRegistry;
use App\Services\Integrations\BankStatementPoller;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\CarrierTrackingPoller;
use App\Services\Integrations\EInvoiceProviderRegistry;
use Illuminate\Http\JsonResponse;

class IntegrationProviderController extends Controller
{
    public function index(
        EInvoiceProviderRegistry $eInvoices,
        BankStatementAdapterRegistry $bankStatements,
        CarrierTrackingAdapterRegistry $carrierTracking,
    ): JsonResponse {
        $eInvoiceKeys = $eInvoices->keys();

        return response()->json([
            'data' => [
                'e_invoice' => [
                    'providers' => array_map(fn (string $key): array => [
                        'key' => $key,
                        'supports_submission' => $eInvoices->supportsSubmission($key),
                        'ready' => $key !== 'http' || trim((string) config('integrations.e_invoice_http_endpoint')) !== '',
                    ], $eInvoiceKeys),
                ],
                'bank_statement' => ['providers' => array_map(function (string $key) use ($bankStatements): array {
                    $adapter = $bankStatements->resolve($key);
                    return ['key' => $key, 'supports_polling' => $adapter instanceof BankStatementPoller, 'ready' => $key !== 'http' || trim((string) config('integrations.bank_statement_http_endpoint')) !== ''];
                }, $bankStatements->keys())],
                'carrier_tracking' => ['providers' => array_map(function (string $key) use ($carrierTracking): array {
                    $adapter = $carrierTracking->resolve($key);
                    return [
                        'key' => $key,
                        'supports_polling' => $adapter instanceof CarrierTrackingPoller,
                        'ready' => $key !== 'http' || trim((string) config('integrations.carrier_tracking_http_endpoint')) !== '',
                    ];
                }, $carrierTracking->keys())],
            ],
        ]);
    }
}
