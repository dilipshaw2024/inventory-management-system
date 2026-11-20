<?php

namespace App\Services\Integrations;

use App\Models\Company;

/** Conservative Norwegian organization-number, MVA, and Peppol/EHF metadata. */
class NorwegianVatCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'norway_vat'; }

    public function countryCodes(): array { return ['NO', 'NOR']; }

    public function registrationSchemes(): array { return ['VAT', 'MVA', 'NO_VAT', 'ORGNR']; }

    public function validateCompany(Company $company): array
    {
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        $number = preg_replace('/\s+/', '', strtoupper((string) $company->tax_registration_number));
        $errors = [];

        if (!in_array($country, $this->countryCodes(), true)) {
            $errors[] = 'The Norway VAT compliance pack requires an NO or NOR country code.';
        }
        if ($scheme !== '' && !in_array($scheme, $this->registrationSchemes(), true)) {
            $errors[] = 'Norwegian registrations must use the VAT, MVA, NO_VAT, or ORGNR scheme.';
        }
        if ($number === '') {
            $errors[] = 'A Norwegian organization number is required for the Norway VAT compliance pack.';
        } elseif (preg_match('/^[0-9]{9}(MVA)?$/', $number) !== 1) {
            $errors[] = 'The Norwegian organization number must contain nine digits, optionally followed by MVA.';
        } elseif (!$this->hasValidOrganizationChecksum(substr($number, 0, 9))) {
            $errors[] = 'The Norwegian organization number has an invalid modulus-11 check digit.';
        }

        return $errors;
    }

    private function hasValidOrganizationChecksum(string $number): bool
    {
        $digits = array_map('intval', str_split($number));
        $weights = [3, 2, 7, 6, 5, 4, 3, 2];
        $remainder = array_sum(array_map(fn (int $digit, int $index): int => $digit * $weights[$index], array_slice($digits, 0, 8), array_keys($weights))) % 11;
        $check = 11 - $remainder;

        return $check !== 10 && $check === $digits[8];
    }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: 'VAT')),
            'tax_registration_number' => preg_replace('/\s+/', '', strtoupper((string) ($company->tax_registration_number ?: ''))) ?: null,
            'currency' => 'NOK',
            'rules' => [
                'tax_identifier' => 'ORGNR_MVA',
                'classification_schemes' => ['NO_HS'],
                'supports_tax_filing' => true,
                'supports_e_invoice' => true,
                'e_invoice_profile' => 'peppol_ehf',
                'requires_certified_gateway' => true,
            ],
        ];
    }
}
