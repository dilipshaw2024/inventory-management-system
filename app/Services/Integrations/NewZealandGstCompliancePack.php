<?php

namespace App\Services\Integrations;

use App\Models\Company;

class NewZealandGstCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'new_zealand_gst'; }
    public function countryCodes(): array { return ['NZ', 'NZL']; }
    public function registrationSchemes(): array { return ['GST', 'IRD', 'NZ_GST']; }
    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code)); $scheme = strtoupper(trim((string) $company->tax_registration_scheme)); $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number)); $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The New Zealand GST compliance pack requires an NZ or NZL country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'New Zealand registrations must use the GST, IRD, or NZ_GST scheme.';
        if ($number === '') $errors[] = 'A New Zealand IRD/GST number is required for the New Zealand GST compliance pack.';
        elseif (preg_match('/^[0-9]{8,9}$/', $number) !== 1) $errors[] = 'The New Zealand IRD/GST number must contain 8 or 9 digits.';
        return $errors;
    }
    public function context(Company $company): array
    {
        return ['country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null, 'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'GST')), 'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null, 'rules' => ['tax_identifier' => 'GST', 'classification_schemes' => ['NZ_HS'], 'supports_tax_filing' => true, 'supports_e_invoice' => false]];
    }
}
