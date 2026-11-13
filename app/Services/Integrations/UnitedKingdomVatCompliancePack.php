<?php

namespace App\Services\Integrations;

use App\Models\Company;

class UnitedKingdomVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'uk_vat'; }

    public function countryCodes(): array { return ['GB', 'UK']; }

    public function registrationSchemes(): array { return ['VAT', 'VATIN', 'UK_VAT']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = strtoupper(trim((string) $company->tax_registration_number));
        $errors = [];

        if (!in_array($country, $this->countryCodes(), true)) {
            $errors[] = 'The UK VAT compliance pack requires a GB or UK country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'UK VAT registrations must use the VAT, VATIN, or UK_VAT scheme.';
        }
        if ($number === '') {
            $errors[] = 'A UK VAT registration number is required for the UK VAT compliance pack.';
        } elseif (preg_match('/^(?:GB)?[0-9A-Z]{9,12}$/', $number) !== 1) {
            $errors[] = 'The UK VAT identifier must contain 9–12 alphanumeric characters, optionally prefixed with GB.';
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
                'classification_schemes' => ['UK_TARIFF'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
            ],
        ];
    }
}
