<?php

namespace App\Services\Integrations;

use App\Models\Company;

class SouthAfricanVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'south_africa_vat'; }
    public function countryCodes(): array { return ['ZA', 'ZAF']; }
    public function registrationSchemes(): array { return ['VAT', 'VATIN', 'ZA_VAT']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The South Africa VAT compliance pack requires a ZA or ZAF country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'South African registrations must use the VAT, VATIN, or ZA_VAT scheme.';
        if ($number === '') $errors[] = 'A South African VAT registration number is required for the South Africa VAT compliance pack.';
        elseif (preg_match('/^[0-9]{10}$/', $number) !== 1) $errors[] = 'The South African VAT number must contain exactly 10 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')), 'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'VAT', 'classification_schemes' => ['ZA_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
