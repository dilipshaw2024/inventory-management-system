<?php

namespace App\Services\Integrations;

use App\Models\Company;

class MexicoVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'mexico_vat'; }
    public function countryCodes(): array { return ['MX', 'MEX']; }
    public function registrationSchemes(): array { return ['VAT', 'IVA', 'RFC']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The Mexico VAT compliance pack requires an MX or MEX country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Mexican registrations must use the VAT, IVA, or RFC scheme.';
        if ($number === '') $errors[] = 'An RFC is required for the Mexico VAT compliance pack.';
        elseif (preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $number) !== 1) $errors[] = 'The Mexican RFC must use the conservative 12- or 13-character legal-entity format.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')), 'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'RFC', 'classification_schemes' => ['MX_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => true]];
    }
}
