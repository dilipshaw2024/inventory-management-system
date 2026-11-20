<?php

namespace App\Services\Integrations;

use App\Models\Company;

class ChinaVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'china_vat'; }

    public function countryCodes(): array { return ['CN', 'CHN']; }

    public function registrationSchemes(): array { return ['VAT', 'CN_VAT', 'USCC', 'TAX']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number));
        $errors = [];
        if (!in_array($country, $this->countryCodes(), true)) $errors[] = 'The China VAT compliance pack requires a CN or CHN country code.';
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) $errors[] = 'Chinese registrations must use the VAT, CN_VAT, USCC, or TAX scheme.';
        if ($number === '') $errors[] = 'A Chinese unified social credit or tax registration number is required for the China VAT compliance pack.';
        elseif (preg_match('/^[0-9A-Z]{18}$/', $number) !== 1) $errors[] = 'The Chinese unified social credit or tax registration number must contain 18 letters or digits.';
        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')),
            'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null,
            'currency' => 'CNY',
            'rules' => [
                'tax_identifier' => 'USCC',
                'classification_schemes' => ['CN_HS'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => false,
                'requires_certified_gateway' => true,
            ],
        ];
    }
}
