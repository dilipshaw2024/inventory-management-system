<?php

namespace App\Services\Integrations;

use App\Models\Company;

class SingaporeGstCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'singapore_gst'; }
    public function countryCodes(): array { return ['SG', 'SIN']; }
    public function registrationSchemes(): array { return ['GST', 'UEN', 'SG_GST']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = strtoupper(preg_replace('/\s+/', '', (string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The Singapore GST compliance pack requires an SG or SIN country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Singapore registrations must use the GST, UEN, or SG_GST scheme.';
        if ($number === '') $errors[] = 'A Singapore UEN is required for the Singapore GST compliance pack.';
        elseif (preg_match('/^[0-9A-Z]{9,10}$/', $number) !== 1) $errors[] = 'The Singapore UEN must contain 9 or 10 alphanumeric characters.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'GST')), 'tax_registration_number' => strtoupper(preg_replace('/\s+/', '', (string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'GST', 'classification_schemes' => ['SG_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
