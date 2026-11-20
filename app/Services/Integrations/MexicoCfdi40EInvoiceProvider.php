<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds a transport-neutral CFDI 4.0 payload for Mexican PAC adapters.
 * PAC authentication, XML sealing, certificate handling and SAT timbrado are
 * intentionally delegated to the configured gateway.
 */
class MexicoCfdi40EInvoiceProvider implements EInvoiceProvider
{
    public function key(): string
    {
        return 'mexico_cfdi_4_0';
    }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $company = $invoice->company;
        $customer = $invoice->customer;
        $concepts = $invoice->invoice_details->values()->map(function ($line, int $index): array {
            $quantity = round((float) ($line->selling_qty ?? 0), 4);
            $unitPrice = round((float) ($line->unit_price ?? 0), 4);
            $amount = round((float) ($line->selling_price ?? ($quantity * $unitPrice)), 2);
            $rate = round((float) ($line->tax_rate ?? 0), 4);
            $tax = round((float) ($line->tax_amount ?? ($amount * $rate / 100)), 2);

            return [
                'NoIdentificacion' => (string) ($line->product?->sku ?: $line->product_id),
                'ClaveProdServ' => $line->product?->classification?->code ?? $line->product?->hsn_code ?? null,
                'Cantidad' => $quantity,
                'ClaveUnidad' => strtoupper((string) ($line->uom ?: 'H87')),
                'Unidad' => $line->uom ?: null,
                'Descripcion' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                'ValorUnitario' => $unitPrice,
                'Importe' => $amount,
                'ObjetoImp' => $rate > 0 ? '02' : '01',
                'Impuestos' => $rate > 0 ? ['Traslados' => [[
                    'Base' => $amount,
                    'Impuesto' => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => $rate / 100,
                    'Importe' => $tax,
                ]]] : null,
                'LineNumber' => $index + 1,
            ];
        })->all();

        $payload = [
            'schema' => 'CFDI-4.0',
            'Version' => '4.0',
            'Comprobante' => [
                'Serie' => null,
                'Folio' => (string) ($invoice->invoice_no ?: 'INV-'.$invoice->id),
                'Fecha' => optional($invoice->date ?: $invoice->created_at)->toIso8601String(),
                'Sello' => null,
                'NoCertificado' => null,
                'Certificado' => null,
                'SubTotal' => $this->amount($invoice->subtotal_amount),
                'Moneda' => strtoupper((string) ($invoice->currency_code ?: 'MXN')),
                'Total' => $this->amount($invoice->total_amount),
                'TipoDeComprobante' => 'I',
                'Exportacion' => '01',
                'MetodoPago' => 'PUE',
                'FormaPago' => null,
                'LugarExpedicion' => null,
            ],
            'Emisor' => [
                'Rfc' => $this->taxNumber($company),
                'Nombre' => (string) ($company?->name ?? ''),
                'RegimenFiscal' => null,
            ],
            'Receptor' => [
                'Rfc' => $this->taxNumber($customer),
                'Nombre' => (string) ($customer?->name ?? ''),
                'DomicilioFiscalReceptor' => null,
                'RegimenFiscalReceptor' => null,
                'UsoCFDI' => 'G03',
            ],
            'Conceptos' => $concepts,
            'Impuestos' => [
                'TotalImpuestosTrasladados' => $this->amount($invoice->tax_amount),
            ],
            'requires_pac_timbrado' => true,
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
