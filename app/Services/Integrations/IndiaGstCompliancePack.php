<?php

namespace App\Services\Integrations;

use App\Models\Company;

class IndiaGstCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'india_gst'; }

    public function countryCodes(): array { return ['IN']; }

    public function registrationSchemes(): array { return ['GST', 'GSTIN', 'INDIA_GST']; }

    public function validateCompany(Company $company): array
    {
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = strtoupper(trim((string) $company->tax_registration_number));
        $errors = [];

        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'India GST registrations must use the GST or GSTIN scheme.';
        }
        if ($number === '') {
            $errors[] = 'An India GSTIN is required for the India GST compliance pack.';
        } elseif (preg_match('/^(0[1-9]|[1-2][0-9]|3[0-8])[A-Z0-9]{13}$/', $number) !== 1) {
            $errors[] = 'The India GSTIN must contain a valid 15-character state-prefixed identifier.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => 'IN',
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'GSTIN')),
            'tax_registration_number' => strtoupper((string) ($company->tax_registration_number ?: '')) ?: null,
            'rules' => [
                'tax_identifier' => 'GSTIN',
                'classification_schemes' => ['HSN', 'SAC'],
                'supports_e_invoice' => true,
                'supports_tax_filing' => true,
            ],
        ];
    }
}
