<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds a transport-neutral UBL 2.1 invoice model.
 *
 * The result intentionally remains an array: a gateway adapter can serialize
 * it to XML/JSON and apply jurisdiction-specific signing without coupling the
 * ERP core to one network or certificate authority.
 */
class Ubl21EInvoiceProvider implements EInvoiceProvider
{
    public function __construct(private ?Ubl21InvoiceXmlSerializer $xmlSerializer = null) {}

    public function key(): string
    {
        return 'ubl_2_1';
    }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $currency = strtoupper((string) ($invoice->currency_code ?: 'INR'));
        $date = optional($invoice->date ?: $invoice->created_at)->toDateString();

        $document = [
            'schema' => 'UBL-Invoice-2.1',
            'customization_id' => 'urn:cen.eu:en16931:2017',
            'profile_id' => 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
            'invoice' => [
                'id' => $invoice->invoice_no ?: 'INV-'.$invoice->id,
                'issue_date' => $date,
                'due_date' => optional($invoice->due_date)->toDateString(),
                'document_currency_code' => $currency,
                'tax_currency_code' => $currency,
                'tax_exclusive_amount' => $this->amount($invoice->subtotal_amount),
                'tax_amount' => $this->amount($invoice->tax_amount),
                'tax_inclusive_amount' => $this->amount($invoice->total_amount),
                'payable_amount' => $this->amount($invoice->total_amount),
            ],
            'supplier_party' => $this->party($invoice->company, null),
            'customer_party' => $this->party($invoice->customer, $invoice->billing_address),
            'lines' => $invoice->invoice_details->values()->map(function ($line): array {
                $rate = (float) ($line->tax_rate ?? 0);
                return [
                    'id' => (string) ($line->id ?: $line->product_id),
                    'invoiced_quantity' => $this->amount($line->selling_qty),
                    'unit_code' => strtoupper((string) ($line->uom ?: 'EA')),
                    'line_extension_amount' => $this->amount($line->selling_price),
                    'price_amount' => $this->amount($line->unit_price),
                    'item' => [
                        'name' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                        'description' => $line->product?->description,
                        'sellers_item_identification' => $line->product?->sku,
                        'classified_tax_category' => $rate > 0 ? 'S' : 'E',
                        'percent' => $rate,
                    ],
                ];
            })->all(),
        ];
        $document['xml'] = ($this->xmlSerializer ?: new Ubl21InvoiceXmlSerializer())->serialize($document);
        return $document;
    }

    private function amount($value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function party($party, ?string $address): ?array
    {
        if (!$party) return null;
        return [
            'name' => $party->name,
            'endpoint_id' => $party->tax_registration_number ?? $party->tax_number ?? null,
            'tax_registration_number' => $party->tax_registration_number ?? $party->tax_number ?? null,
            'address' => $address,
        ];
    }
}
