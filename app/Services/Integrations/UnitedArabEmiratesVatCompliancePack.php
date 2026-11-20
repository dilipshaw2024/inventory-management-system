<?php

namespace App\Services\Integrations;

use App\Models\Company;

class UnitedArabEmiratesVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'uae_vat'; }
    public function countryCodes(): array { return ['AE', 'UAE']; }
    public function registrationSchemes(): array { return ['VAT', 'VATIN', 'UAE_VAT', 'TRN']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = strtoupper(trim((string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The UAE VAT compliance pack requires an AE or UAE country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'UAE registrations must use the VAT, VATIN, UAE_VAT, or TRN scheme.';
        if ($number === '') $errors[] = 'A UAE VAT registration number is required for the UAE VAT compliance pack.';
        elseif (preg_match('/^[0-9]{15}$/', $number) !== 1) $errors[] = 'The UAE TRN must contain exactly 15 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')), 'tax_registration_number' => strtoupper((string) ($company->tax_registration_number ?: '')) ?: null, 'rules' => ['tax_identifier' => 'VAT', 'classification_schemes' => ['AE_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
