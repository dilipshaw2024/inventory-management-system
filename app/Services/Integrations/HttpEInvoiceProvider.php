<?php

namespace App\Services\Integrations;

use App\Models\EInvoiceSubmission;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Opt-in provider adapter for jurisdictions or gateways that expose an HTTP
 * e-invoice endpoint. The endpoint is deliberately configuration-driven so
 * deployments can supply their own certified gateway without changing ERP
 * code or exposing credentials in the database.
 */
class HttpEInvoiceProvider implements EInvoiceProvider, EInvoiceSubmitter
{
    public function key(): string
    {
        return 'http';
    }

    public function prepare(Invoice $invoice): array
    {
        return app(GenericEInvoiceProvider::class)->prepare($invoice);
    }

    public function submit(EInvoiceSubmission $submission): array
    {
        $endpoint = trim((string) config('integrations.e_invoice_http_endpoint'));
        if ($endpoint === '') {
            throw new \RuntimeException('The HTTP e-invoice endpoint is not configured.');
        }

        $payload = [
            'provider' => $submission->provider,
            'external_reference' => 'ERP-EINV-'.$submission->id,
            'payload_hash' => $submission->payload_hash,
            'payload' => $submission->payload,
        ];
        $retryCount = max(0, min(5, (int) config('integrations.e_invoice_http_retries', 2)));
        $retrySleep = max(0, (int) config('integrations.e_invoice_http_retry_sleep', 0));
        $response = null;
        for ($attempt = 0; $attempt <= $retryCount; $attempt++) {
            try {
                $request = Http::timeout((int) config('integrations.e_invoice_http_timeout', 30))
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'Idempotency-Key' => $payload['external_reference'],
                        'X-ERP-Payload-Hash' => (string) $submission->payload_hash,
                    ]);
                $token = trim((string) config('integrations.e_invoice_http_token'));
                if ($token !== '') $request = $request->withToken($token);
                $response = $request->post($endpoint, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt >= $retryCount) throw new \RuntimeException('HTTP e-invoice provider connection failed: '.$exception->getMessage(), 0, $exception);
                if ($retrySleep > 0) usleep($retrySleep * 1000);
                continue;
            }

            $retryable = $response->status() === 408 || $response->status() === 425 || $response->status() === 429 || $response->serverError();
            if (!$retryable || $attempt >= $retryCount) break;
            if ($retrySleep > 0) usleep($retrySleep * 1000);
        }

        if (!$response) throw new \RuntimeException('HTTP e-invoice provider returned no response.');
        if ($response->failed()) {
            $message = (string) ($response->json('message') ?: $response->body());
            throw new \RuntimeException('HTTP e-invoice provider returned '.$response->status().': '.($message ?: 'request failed'));
        }

        $body = $response->json();
        if (!is_array($body)) throw new \RuntimeException('HTTP e-invoice provider returned an invalid JSON response.');

        $status = strtolower((string) ($body['status'] ?? 'submitted'));
        if (!in_array($status, ['submitted', 'accepted', 'rejected', 'pending'], true)) {
            throw new \RuntimeException('HTTP e-invoice provider returned an invalid status.');
        }

        return [
            'status' => $status,
            'external_reference' => $body['external_reference'] ?? $body['reference'] ?? ('ERP-EINV-'.$submission->id),
            'response' => $body,
        ];
    }
}
