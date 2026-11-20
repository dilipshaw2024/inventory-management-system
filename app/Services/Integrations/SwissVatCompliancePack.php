<?php

namespace App\Services\Integrations;

use App\Models\Company;

/** Conservative Swiss UID/MWST identity and VAT capability metadata. */
class SwissVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'switzerland_vat'; }

    public function countryCodes(): array { return ['CH', 'CHE']; }

    public function registrationSchemes(): array { return ['VAT', 'MWST', 'TVA', 'IVA', 'UID', 'CH_VAT']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = strtoupper(trim((string) $company->tax_registration_number));
        $normalized = preg_replace('/[\s.]+/', '', $number);
        $errors = [];

        if (!in_array($country, $this->countryCodes(), true)) {
            $errors[] = 'The Swiss VAT compliance pack requires a CH or CHE country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'Swiss registrations must use the VAT, MWST, TVA, IVA, UID, or CH_VAT scheme.';
        }
        if ($normalized === '') {
            $errors[] = 'A Swiss UID/MWST registration number is required for the Swiss VAT compliance pack.';
        } elseif (preg_match('/^CHE-?[0-9]{9}(MWST|TVA|IVA)?$/', $normalized) !== 1) {
            $errors[] = 'The Swiss UID/MWST number must contain CHE followed by nine digits and an optional MWST, TVA, or IVA suffix.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        $number = strtoupper(trim((string) ($company->tax_registration_number ?: '')));
        $number = preg_replace('/[\s.]+/', '', $number);

        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')),
            'tax_registration_number' => $number ?: null,
            'currency' => 'CHF',
            'rules' => [
                'tax_identifier' => 'UID_MWST',
                'classification_schemes' => ['CH_HS'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
                'requires_certified_gateway' => true,
                'vat_rates' => [
                    'standard' => 8.1,
                    'reduced' => 2.6,
                    'accommodation' => 3.8,
                ],
            ],
        ];
    }
}
