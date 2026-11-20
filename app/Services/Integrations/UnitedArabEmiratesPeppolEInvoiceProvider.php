<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

/**
 * UAE structured e-invoice profile over the portable UBL/Peppol model.
 * Accredited ASP onboarding, participant identifiers, AS4 transport and FTA
 * reporting remain deployment-specific gateway responsibilities.
 */
class UnitedArabEmiratesPeppolEInvoiceProvider extends Ubl21EInvoiceProvider
{
    public function key(): string { return 'uae_peppol_einvoice'; }

    public function prepare(Invoice $invoice): array
    {
        $payload = parent::prepare($invoice);
        $currency = strtoupper((string) ($invoice->currency_code ?: 'AED'));
        $payload['schema'] = 'UAE_EINVOICE_UBL_2_1';
        $payload['invoice']['document_currency_code'] = $currency;
        $payload['invoice']['tax_currency_code'] = 'AED';
        $payload['uae'] = [
            'model' => 'peppol_4_corner',
            'requires_accredited_service_provider' => true,
            'requires_structured_e_invoice_exchange' => true,
            'requires_fta_reporting' => true,
            'participant_identifier' => null,
            'branch_trade_license_number' => null,
        ];
        $payload['xml'] = (new Ubl21InvoiceXmlSerializer())->serialize($payload);
        return $payload;
    }
}
