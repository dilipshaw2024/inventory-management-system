<?php

namespace App\Services\Integrations;

use App\Models\Company;

class GenericRegionalCompliancePack implements RegionalCompliancePack
{
    public function key(): string { return 'generic'; }

    public function countryCodes(): array { return []; }

    public function registrationSchemes(): array { return []; }

    public function validateCompany(Company $company): array { return []; }

    public function context(Company $company): array
    {
        return [
            'country_code' => strtoupper((string) ($company->country_code ?: '')) ?: null,
            'tax_registration_scheme' => strtoupper((string) ($company->tax_registration_scheme ?: '')) ?: null,
            'tax_registration_number' => $company->tax_registration_number ?: null,
            'rules' => [],
        ];
    }
}
