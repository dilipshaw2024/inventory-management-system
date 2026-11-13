<?php

namespace App\Services\Integrations;

use App\Models\Company;

class EuropeanVatCompliancePack implements RegionalCompliancePack
{
    /** @var list<string> */
    private const COUNTRIES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'GR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public function key(): string { return 'eu_vat'; }

    public function countryCodes(): array { return self::COUNTRIES; }

    public function registrationSchemes(): array { return ['VAT', 'VATIN', 'EU_VAT']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = strtoupper(trim((string) $company->tax_registration_number));
        $errors = [];

        if (!in_array($country, self::COUNTRIES, true)) {
            $errors[] = 'The EU VAT compliance pack requires an EU member-state country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'EU VAT registrations must use the VAT or VATIN scheme.';
        }
        if ($number === '') {
            $errors[] = 'An EU VAT registration number is required for the EU VAT compliance pack.';
        } elseif (preg_match('/^'.preg_quote($country, '/').'[A-Z0-9]{4,14}$/', $number) !== 1) {
            $errors[] = 'The EU VAT identifier must begin with the company country code and contain 6–15 characters.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')),
            'tax_registration_number' => strtoupper((string) ($company->tax_registration_number ?: '')) ?: null,
            'rules' => [
                'tax_identifier' => 'VAT',
                'classification_schemes' => ['CN'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
            ],
        ];
    }
}
