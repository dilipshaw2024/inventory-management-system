<?php

namespace App\Services\Integrations;

use App\Models\Company;

/** Conservative ZATCA identity and Phase 2 capability metadata. */
class SaudiVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'saudi_vat'; }
    public function countryCodes(): array { return ['SA', 'SAU']; }
    public function registrationSchemes(): array { return ['VAT', 'VATIN', 'ZATCA', 'SA_VAT']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number));
        $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The Saudi VAT compliance pack requires an SA or SAU country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Saudi registrations must use the VAT, VATIN, ZATCA, or SA_VAT scheme.';
        if ($number === '') $errors[] = 'A Saudi VAT registration number is required for the Saudi VAT compliance pack.';
        elseif (preg_match('/^3[0-9]{13}3$/', $number) !== 1) $errors[] = 'The Saudi VAT number must contain 15 digits and start and end with 3.';
        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')),
            'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null,
            'rules' => [
                'tax_identifier' => 'VATIN',
                'classification_schemes' => ['SA_HS'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => true,
                'e_invoice_phase' => 'phase_2_integration',
                'requires_xml' => true,
                'requires_zatca_clearance_or_reporting' => true,
                'requires_cryptographic_stamp_and_qr' => true,
            ],
        ];
    }
}
