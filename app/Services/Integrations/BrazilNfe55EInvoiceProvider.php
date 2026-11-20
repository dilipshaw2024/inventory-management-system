<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * Builds a transport-neutral NF-e model (layout 4.00, model 55).
 *
 * SEFAZ signing, certificate handling, XML canonicalization and submission are
 * deliberately left to a configured gateway adapter. The ERP still produces a
 * deterministic, auditable payload that can be handed to such an adapter.
 */
class BrazilNfe55EInvoiceProvider implements EInvoiceProvider
{
    public function key(): string
    {
        return 'nfe_55_brazil';
    }

    public function prepare(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'invoice_details.product']);
        $company = $invoice->company;
        $customer = $invoice->customer;
        $issueDate = optional($invoice->date ?: $invoice->created_at)->toIso8601String();
        $lines = $invoice->invoice_details->values()->map(function ($line, int $index): array {
            $quantity = round((float) ($line->selling_qty ?? 0), 4);
            $unitPrice = round((float) ($line->unit_price ?? 0), 4);
            $lineValue = round((float) ($line->selling_price ?? ($quantity * $unitPrice)), 2);
            $taxRate = round((float) ($line->tax_rate ?? 0), 4);

            return [
                'nItem' => $index + 1,
                'prod' => [
                    'cProd' => (string) ($line->product?->sku ?: $line->product_id),
                    'xProd' => (string) ($line->product?->name ?: 'Item '.$line->product_id),
                    'NCM' => $line->product?->classification?->code ?? $line->product?->hsn_code ?? null,
                    'uCom' => strtoupper((string) ($line->uom ?: 'UN')),
                    'qCom' => $quantity,
                    'vUnCom' => $unitPrice,
                    'vProd' => $lineValue,
                ],
                'imposto' => [
                    'tax_rate' => $taxRate,
                    'vTotTrib' => round($lineValue * ($taxRate / 100), 2),
                ],
            ];
        })->all();

        $payload = [
            'schema' => 'NF-e-4.00',
            'model' => '55',
            'version' => '4.00',
            'requires_authority_signing' => true,
            'requires_sefaz_gateway' => true,
            'ide' => [
                'nNF' => (string) ($invoice->invoice_no ?: 'INV-'.$invoice->id),
                'dhEmi' => $issueDate,
                'tpNF' => 1,
                'finNFe' => 1,
                'cUF' => null,
            ],
            'emit' => $this->party($company),
            'dest' => $this->party($customer),
            'det' => $lines,
            'total' => [
                'vProd' => $this->amount($invoice->subtotal_amount),
                'vBC' => $this->amount($invoice->subtotal_amount),
                'vICMS' => $this->amount($invoice->tax_amount),
                'vNF' => $this->amount($invoice->total_amount),
            ],
            'pag' => [[
                'tPag' => '99',
                'vPag' => $this->amount($invoice->total_amount),
            ]],
        ];

        $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return $payload;
    }

    private function amount($value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function party($party): ?array
    {
        if (!$party) return null;
        $taxNumber = preg_replace('/\D+/', '', (string) ($party->tax_registration_number ?? $party->tax_number ?? '')) ?: null;
        return [
            'xNome' => (string) ($party->name ?? ''),
            'CNPJ_CPF' => $taxNumber,
            'ender' => $party->billing_address ?? $party->address ?? null,
        ];
    }
}
