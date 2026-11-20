<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds a transport-neutral Korean electronic tax-invoice payload.
 * NTS/ASP authentication, signing and transmission remain configurable
 * deployment responsibilities.
 */
class SouthKoreaElectronicTaxInvoiceProvider implements EInvoiceProvider
{
    public function key(): string { return 'south_korea_e_tax_invoice'; }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $lines = $invoice->invoice_details->values()->map(function ($line, int $index): array {
            $quantity = round((float) ($line->selling_qty ?? 0), 4);
            $unitPrice = round((float) ($line->unit_price ?? 0), 2);
            $supplyAmount = round((float) ($line->selling_price ?? ($quantity * $unitPrice)), 0);
            $rate = round((float) ($line->tax_rate ?? 0), 4);
            $vatAmount = round((float) ($line->tax_amount ?? ($supplyAmount * $rate / 100)), 0);
            return [
                'line_number' => $index + 1,
                'item_code' => (string) ($line->product?->sku ?: $line->product_id),
                'item_name' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'supply_amount' => $supplyAmount,
                'vat_rate' => $rate,
                'vat_amount' => $vatAmount,
            ];
        })->all();
        $payload = [
            'schema' => 'KOREA_E_TAX_INVOICE',
            'invoice' => [
                'number' => (string) ($invoice->invoice_no ?: 'INV-'.$invoice->id),
                'issue_date' => optional($invoice->date ?: $invoice->created_at)->toDateString(),
                'currency' => strtoupper((string) ($invoice->currency_code ?: 'KRW')),
                'supply_amount' => $this->amount($invoice->subtotal_amount),
                'vat_amount' => $this->amount($invoice->tax_amount),
                'total_amount' => $this->amount($invoice->total_amount),
            ],
            'supplier' => ['name' => (string) ($invoice->company?->name ?? ''), 'business_registration_number' => $this->taxNumber($invoice->company)],
            'recipient' => ['name' => (string) ($invoice->customer?->name ?? ''), 'business_registration_number' => $this->taxNumber($invoice->customer)],
            'lines' => $lines,
            'south_korea' => ['electronic_tax_invoice_required' => true, 'submission_channel' => 'nts_or_authorized_asp', 'requires_certified_gateway' => true],
        ];
        $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return $payload;
    }

    private function amount($value): float { return round((float) ($value ?? 0), 0); }

    private function taxNumber($party): ?string
    {
        if (!$party) return null;
        return preg_replace('/\s+/', '', (string) ($party->tax_registration_number ?? $party->tax_number ?? '')) ?: null;
    }
}
