<?php

namespace App\Services\Integrations;

use App\Models\Company;

class AustralianGstCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'australia_gst'; }
    public function countryCodes(): array { return ['AU', 'AUS']; }
    public function registrationSchemes(): array { return ['GST', 'ABN', 'AU_GST']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', strtoupper(trim((string) $company->tax_registration_number))); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The Australia GST compliance pack requires an AU or AUS country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Australian registrations must use the GST, ABN, or AU_GST scheme.';
        if ($number === '') $errors[] = 'An Australian Business Number is required for the Australia GST compliance pack.';
        elseif (preg_match('/^[0-9]{11}$/', $number) !== 1) $errors[] = 'The Australian Business Number must contain exactly 11 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'GST')), 'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'GST', 'classification_schemes' => ['AU_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
