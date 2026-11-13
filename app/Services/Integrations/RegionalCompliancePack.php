<?php

namespace App\Services\Integrations;

use App\Models\Company;

interface RegionalCompliancePack
{
    public function key(): string;

    /** @return list<string> */
    public function countryCodes(): array;

    /** @return list<string> */
    public function registrationSchemes(): array;

    /** @return list<string> */
    public function validateCompany(Company $company): array;

    /** @return array<string, mixed> */
    public function context(Company $company): array;
}
