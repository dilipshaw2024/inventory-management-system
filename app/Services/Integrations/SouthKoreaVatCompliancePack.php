<?php

namespace App\Services\Integrations;

use App\Models\Company;

class SouthKoreaVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'south_korea_vat'; }
    public function countryCodes(): array { return ['KR', 'KOR']; }
    public function registrationSchemes(): array { return ['VAT', 'KR_VAT', 'BRN']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', (string) $company->tax_registration_number); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The South Korea VAT compliance pack requires a KR or KOR country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'South Korean registrations must use the VAT, KR_VAT, or BRN scheme.';
        if ($number === '') $errors[] = 'A South Korean business registration number is required for the South Korea VAT compliance pack.';
        elseif (preg_match('/^[0-9]{10}$/', $number) !== 1) $errors[] = 'The South Korean business registration number must contain 10 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')), 'tax_registration_number' => preg_replace('/\s+/', '', (string) ($company->tax_registration_number ?: '')) ?: null, 'rules' => ['tax_identifier' => 'BRN', 'classification_schemes' => ['KR_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
