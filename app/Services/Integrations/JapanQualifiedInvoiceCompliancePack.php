<?php

namespace App\Services\Integrations;

use App\Models\Company;

class JapanQualifiedInvoiceCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'japan_qualified_invoice'; }
    public function countryCodes(): array { return ['JP', 'JPN']; }
    public function registrationSchemes(): array { return ['VAT', 'CONSUMPTION_TAX', 'JCT', 'INVOICE']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The Japan qualified-invoice compliance pack requires a JP or JPN country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Japanese registrations must use the VAT, CONSUMPTION_TAX, JCT, or INVOICE scheme.';
        if ($number === '') $errors[] = 'A Japan qualified invoice registration number is required for the Japan compliance pack.';
        elseif (preg_match('/^T[0-9]{13}$/', $number) !== 1) $errors[] = 'The Japan qualified invoice registration number must start with T and contain 13 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'JCT')), 'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'JCT', 'classification_schemes' => ['JP_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => true]];
    }
}
