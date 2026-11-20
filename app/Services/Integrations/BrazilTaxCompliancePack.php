<?php

namespace App\Services\Integrations;

use App\Models\Company;

/** Conservative Brazil tax identity and NF-e capability metadata. */
class BrazilTaxCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'brazil_tax'; }

    public function countryCodes(): array { return ['BR', 'BRA']; }

    public function registrationSchemes(): array { return ['CNPJ', 'CPF', 'ICMS', 'ISS', 'BR_TAX']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = preg_replace('/\D+/', '', (string) $company->tax_registration_number);
        $errors = [];

        if (!in_array($country, $this->countryCodes(), true)) {
            $errors[] = 'The Brazil tax compliance pack requires a BR or BRA country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'Brazil registrations must use the CNPJ, CPF, ICMS, ISS, or BR_TAX scheme.';
        }
        if ($number === '') {
            $errors[] = 'A CNPJ or CPF is required for the Brazil tax compliance pack.';
        } elseif (!in_array(strlen($number), [11, 14], true)) {
            $errors[] = 'The Brazil tax registration number must contain 11 CPF or 14 CNPJ digits.';
        }

        return $errors;
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: 'BR')),
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'CNPJ')),
            'tax_registration_number' => preg_replace('/\D+/', '', (string) ($company->tax_registration_number ?: '')) ?: null,
            'rules' => [
                'tax_identifier' => 'CNPJ_OR_CPF',
                'classification_schemes' => ['NCM', 'NBS'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => true,
                'e_invoice_standard' => 'NF-e',
            ],
        ];
    }
}
