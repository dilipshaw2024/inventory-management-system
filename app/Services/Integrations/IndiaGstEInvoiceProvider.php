<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds the transport-neutral JSON shape used by India's GST e-invoice IRP
 * integrations. Authentication, signing, IRN generation and submission remain
 * responsibilities of the configured certified gateway adapter.
 */
class IndiaGstEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string
    {
        return 'india_gst_irp';
    }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $company = $invoice->company;
        $customer = $invoice->customer;
        $items = $invoice->invoice_details->values()->map(function ($line, int $index): array {
            $quantity = round((float) ($line->selling_qty ?? 0), 4);
            $unitPrice = round((float) ($line->unit_price ?? 0), 4);
            $assessable = round((float) ($line->selling_price ?? ($quantity * $unitPrice)), 2);
            $rate = round((float) ($line->tax_rate ?? 0), 4);
            $tax = round((float) ($line->tax_amount ?? ($assessable * $rate / 100)), 2);

            return [
                'SlNo' => (string) ($index + 1),
                'PrdDesc' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                'IsServc' => 'N',
                'HsnCd' => $line->product?->classification?->code ?? $line->product?->hsn_code ?? null,
                'Qty' => $quantity,
                'Unit' => strtoupper((string) ($line->uom ?: 'EA')),
                'UnitPrice' => $unitPrice,
                'TotAmt' => $assessable,
                'AssAmt' => $assessable,
                'GstRt' => $rate,
                'TaxAmount' => $tax,
                'TotItemVal' => round($assessable + $tax, 2),
            ];
        })->all();

        $payload = [
            'schema' => 'GST-India-eInvoice-1.1',
            'Version' => '1.1',
            'TranDtls' => [
                'TaxSch' => 'GST',
                'SupTyp' => 'B2B',
                'RegRev' => 'N',
                'EcmGstin' => null,
            ],
            'DocDtls' => [
                'Typ' => 'INV',
                'No' => (string) ($invoice->invoice_no ?: 'INV-'.$invoice->id),
                'Dt' => optional($invoice->date ?: $invoice->created_at)->format('d/m/Y'),
            ],
            'SellerDtls' => [
                'Gstin' => $this->taxNumber($company),
                'LglNm' => (string) ($company?->name ?? ''),
            ],
            'BuyerDtls' => [
                'Gstin' => $this->taxNumber($customer),
                'LglNm' => (string) ($customer?->name ?? ''),
            ],
            'ItemList' => $items,
            'ValDtls' => [
                'AssVal' => $this->amount($invoice->subtotal_amount),
                'TotInvVal' => $this->amount($invoice->total_amount),
                'TaxVal' => $this->amount($invoice->tax_amount),
            ],
            'requires_irp_signing' => true,
            'requires_certified_gateway' => true,
        ];

        $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return $payload;
    }

    private function amount($value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function taxNumber($party): ?string
    {
        if (!$party) return null;
        return strtoupper(preg_replace('/\s+/', '', (string) ($party->tax_registration_number ?? $party->tax_number ?? ''))) ?: null;
    }
}
