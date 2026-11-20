<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds a transport-neutral ZATCA Phase 2 invoice model.
 * CSID signing, QR generation, clearance/reporting and FATOORA API calls
 * remain responsibilities of the certified deployment gateway.
 */
class SaudiZatcaEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string { return 'saudi_zatca_phase_2'; }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $company = $invoice->company;
        $customer = $invoice->customer;
        $currency = strtoupper((string) ($invoice->currency_code ?: 'SAR'));
        $lines = $invoice->invoice_details->values()->map(function ($line, int $index): array {
            $quantity = round((float) ($line->selling_qty ?? 0), 4);
            $unitPrice = round((float) ($line->unit_price ?? 0), 2);
            $amount = round((float) ($line->selling_price ?? ($quantity * $unitPrice)), 2);
            $rate = round((float) ($line->tax_rate ?? 0), 4);
            $tax = round((float) ($line->tax_amount ?? ($amount * $rate / 100)), 2);
            return [
                'id' => (string) ($line->product?->sku ?: $line->product_id),
                'name' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                'classification_code' => $line->product?->classification?->code ?? $line->product?->hsn_code ?? null,
                'quantity' => $quantity,
                'unit_code' => strtoupper((string) ($line->uom ?: 'PCE')),
                'unit_price' => $unitPrice,
                'line_extension_amount' => $amount,
                'tax_category' => $rate > 0 ? 'S' : 'Z',
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'line_number' => $index + 1,
            ];
        })->all();

        $payload = [
            'schema' => 'ZATCA_PHASE_2_UBL_2_1',
            'invoice' => [
                'uuid' => $this->uuid($invoice),
                'id' => (string) ($invoice->invoice_no ?: 'INV-'.$invoice->id),
                'issue_date' => optional($invoice->date ?: $invoice->created_at)->toDateString(),
                'invoice_type_code' => '388',
                'document_currency_code' => $currency,
                'tax_currency_code' => 'SAR',
                'tax_exclusive_amount' => $this->amount($invoice->subtotal_amount),
                'tax_amount' => $this->amount($invoice->tax_amount),
                'payable_amount' => $this->amount($invoice->total_amount),
            ],
            'seller' => ['vat_number' => $this->taxNumber($company), 'name' => (string) ($company?->name ?? '')],
            'buyer' => ['vat_number' => $this->taxNumber($customer), 'name' => (string) ($customer?->name ?? '')],
            'lines' => $lines,
            'zatca' => [
                'phase' => 2,
                'clearance_or_reporting_required' => true,
                'cryptographic_stamp' => null,
                'qr_code' => null,
                'requires_csid_gateway' => true,
            ],
            'requires_certified_gateway' => true,
        ];
        $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return $payload;
    }

    private function amount($value): float { return round((float) ($value ?? 0), 2); }
    private function taxNumber($party): ?string
    {
        if (!$party) return null;
        return strtoupper(preg_replace('/\s+/', '', (string) ($party->tax_registration_number ?? $party->tax_number ?? ''))) ?: null;
    }
    private function uuid(Invoice $invoice): string
    {
        return sprintf('%08x-%04x-%04x-%04x-%012x', (int) ($invoice->company_id ?: 0), (int) ($invoice->id ?: 0), 0x4000, 0x8000, crc32((string) ($invoice->invoice_no ?: $invoice->id)) & 0xffffffffffff);
    }
}
