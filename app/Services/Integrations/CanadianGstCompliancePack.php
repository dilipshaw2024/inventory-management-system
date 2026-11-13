<?php

namespace App\Services\Integrations;

use App\Models\Company;

class CanadianGstCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'canada_gst_hst'; }

    public function countryCodes(): array { return ['CA']; }

    public function registrationSchemes(): array { return ['GST', 'GST_HST', 'HST', 'BN']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = strtoupper(trim((string) $company->tax_registration_number));
        $errors = [];

        if ($country !== 'CA') $errors[] = 'The Canada GST/HST compliance pack requires a CA country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'Canadian registrations must use the GST, GST_HST, HST, or BN scheme.';
        }
        if ($number === '') {
            $errors[] = 'A Canadian business or GST/HST registration number is required for the Canada GST/HST compliance pack.';
        } elseif (preg_match('/^[0-9]{9}(?:RT[0-9]{4})?$/', $number) !== 1) {
            $errors[] = 'The Canadian identifier must be a 9-digit business number or a 15-character GST/HST account number.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'GST_HST')),
            'tax_registration_number' => strtoupper((string) ($company->tax_registration_number ?: '')) ?: null,
            'rules' => [
                'tax_identifier' => 'GST_HST',
                'classification_schemes' => ['CA_TARIFF'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
            ],
        ];
    }
}
