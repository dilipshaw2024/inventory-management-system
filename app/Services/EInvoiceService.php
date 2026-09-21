<?php

namespace App\Services;

use App\Models\EInvoiceSubmission;
use App\Models\Invoice;
use App\Services\Integrations\EInvoiceProviderRegistry;
use App\Services\Integrations\EInvoiceSubmitter;
use Illuminate\Support\Facades\DB;

class EInvoiceService
{
    public function __construct(private EInvoiceProviderRegistry $providers) {}

    public function prepare(Invoice $invoice, string $provider, ?int $userId = null): EInvoiceSubmission
    {
        if ((int) $invoice->status !== 1) throw new \RuntimeException('Only approved sales invoices can be prepared for e-invoicing.');
        $providerAdapter = $this->providers->resolve($provider);
        $companyId = $invoice->company_id;
        return DB::transaction(function () use ($invoice, $provider, $providerAdapter, $userId, $companyId): EInvoiceSubmission {
            $existing = EInvoiceSubmission::where('company_id', $companyId)->where('invoice_id', $invoice->id)->where('provider', $provider)->first();
            if ($existing) return $existing;
            $payload = $providerAdapter->prepare($invoice->loadMissing(['customer', 'invoice_details.product']));
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return EInvoiceSubmission::create([
                'company_id' => $companyId, 'invoice_id' => $invoice->id, 'provider' => $provider,
                'status' => 'prepared', 'payload_hash' => $hash, 'payload' => $payload, 'created_by' => $userId,
            ]);
        });
    }

    public function submit(EInvoiceSubmission $submission): EInvoiceSubmission
    {
        if (!in_array($submission->status, ['prepared', 'failed'], true)) {
            throw new \RuntimeException('Only prepared or failed e-invoices can be submitted.');
        }
        $provider = $this->providers->resolve($submission->provider);
        if (!$provider instanceof EInvoiceSubmitter) {
            throw new \RuntimeException('The configured e-invoice provider does not support live submission.');
        }
        try {
            $result = $provider->submit($submission);
            $status = (string) ($result['status'] ?? 'submitted');
            if (!in_array($status, ['submitted', 'accepted', 'rejected', 'pending'], true)) {
                throw new \RuntimeException('The e-invoice provider returned an invalid status.');
            }
        } catch (\Throwable $exception) {
            $submission->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            throw new \RuntimeException('E-invoice submission failed: '.$exception->getMessage(), 0, $exception);
        }
        $submission->update([
            'status' => $status,
            'provider_response' => $result['response'] ?? null,
            'external_reference' => $result['external_reference'] ?? null,
            'error_message' => null,
            'submitted_at' => $submission->submitted_at ?: now(),
            'acknowledged_at' => in_array($status, ['accepted', 'rejected'], true) ? now() : $submission->acknowledged_at,
        ]);
        return $submission->fresh();
    }

    public function acknowledge(string $provider, string $externalReference, string $status, ?array $response = null): EInvoiceSubmission
    {
        $submission = EInvoiceSubmission::where('provider', $provider)->where('external_reference', $externalReference)->first();
        if (!$submission) throw new \RuntimeException('No matching e-invoice submission was found.');
        if (!in_array($status, ['submitted', 'accepted', 'rejected', 'pending'], true)) throw new \RuntimeException('Invalid e-invoice callback status.');
        return DB::transaction(function () use ($submission, $status, $response): EInvoiceSubmission {
            $locked = EInvoiceSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['accepted', 'rejected'], true)) {
                if ($locked->status !== $status) throw new \RuntimeException('The e-invoice submission already has a final status.');
                return $locked;
            }
            $locked->update([
                'status' => $status,
                'provider_response' => $response,
                'error_message' => $status === 'rejected' ? ($response['message'] ?? 'Provider rejected the e-invoice.') : null,
                'submitted_at' => $locked->submitted_at ?: now(),
                'acknowledged_at' => in_array($status, ['accepted', 'rejected'], true) ? now() : null,
            ]);
            return $locked->fresh();
        });
    }
}
