<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

class GenericEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string { return 'generic'; }

    public function prepare(Invoice $invoice): array
    {
        return [
            'schema' => 'erp.einvoice.v1',
            'document_type' => 'sales_invoice',
            'invoice_number' => $invoice->invoice_no ?: 'INV-'.$invoice->id,
            'invoice_date' => optional($invoice->date ?: $invoice->created_at)->toDateString(),
            'currency_code' => $invoice->currency_code ?: 'INR',
            'tax_jurisdiction' => $invoice->tax_jurisdiction,
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'name' => $invoice->customer->name,
                'tax_number' => $invoice->customer->tax_number ?? null,
                'billing_address' => $invoice->billing_address,
                'shipping_address' => $invoice->shipping_address,
            ] : null,
            'totals' => [
                'subtotal' => (float) $invoice->subtotal_amount,
                'tax' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total_amount,
            ],
            'lines' => $invoice->invoice_details->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'sku' => $line->product?->sku,
                'description' => $line->product?->name,
                'quantity' => (float) $line->selling_qty,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) ($line->tax_rate ?? 0),
                'tax_amount' => (float) ($line->tax_amount ?? 0),
                'line_total' => (float) $line->selling_price,
            ])->values()->all(),
        ];
    }
}
