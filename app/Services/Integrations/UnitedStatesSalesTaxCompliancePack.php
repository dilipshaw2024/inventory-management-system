<?php

namespace App\Services\Integrations;

use App\Models\Company;

/**
 * Conservative US localization metadata. Sales-tax nexus/rate calculation is
 * intentionally delegated to the configured tax-rate engine or provider.
 */
class UnitedStatesSalesTaxCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'united_states_sales_tax'; }

    public function countryCodes(): array { return ['US', 'USA']; }

    public function registrationSchemes(): array { return ['EIN', 'FEIN', 'SALES_TAX', 'US_SALES_TAX']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number));
        $errors = [];

        if (!in_array($country, $this->countryCodes(), true)) {
            $errors[] = 'The United States sales-tax compliance pack requires a US or USA country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'United States registrations must use the EIN, FEIN, SALES_TAX, or US_SALES_TAX scheme.';
        }
        if ($number === '') {
            $errors[] = 'An EIN or state sales-tax registration number is required for the United States sales-tax compliance pack.';
        } elseif (preg_match('/^(\d{2}-?\d{7}|[A-Z0-9-]{4,30})$/', $number) !== 1) {
            $errors[] = 'The United States tax registration number must use a conservative EIN or state registration format.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: 'US')),
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'EIN')),
            'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null,
            'rules' => [
                'tax_identifier' => 'EIN_OR_STATE_SALES_TAX_ID',
                'classification_schemes' => ['US_HS', 'UNSPSC'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
                'requires_nexus_provider' => true,
            ],
        ];
    }
}
