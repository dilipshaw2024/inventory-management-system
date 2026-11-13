<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\Integrations\RegionalCompliancePackRegistry;
use Tests\TestCase;

class RegionalCompliancePackRegistryTest extends TestCase
{
    public function test_company_selects_india_gst_pack_and_validates_gstin(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'IN', 'tax_registration_scheme' => 'GSTIN', 'tax_registration_number' => '27ABCDE1234F1Z5']);
        $pack = $registry->forCompany($company);

        $this->assertSame('india_gst', $pack->key());
        $this->assertSame([], $pack->validateCompany($company));
        $this->assertSame('GSTIN', $pack->context($company)['rules']['tax_identifier']);
    }

    public function test_unknown_region_uses_generic_pack_and_custom_packs_are_discoverable(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'US', 'tax_registration_scheme' => 'EIN', 'tax_registration_number' => '12-3456789']);

        $this->assertSame('generic', $registry->forCompany($company)->key());
        $this->assertContains('india_gst', $registry->keys());
        $this->assertContains('generic', $registry->keys());
    }

    public function test_eu_company_selects_vat_pack_and_rejects_wrong_country_prefix(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'DE', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => 'DE123456789']);

        $this->assertSame('eu_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));

        $company->tax_registration_number = 'FR123456789';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_uk_company_selects_uk_vat_pack(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'GB', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => 'GB123456789']);

        $this->assertSame('uk_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame(['UK_TARIFF'], $registry->forCompany($company)->context($company)['rules']['classification_schemes']);
    }

    public function test_canada_company_selects_gst_hst_pack_and_rejects_invalid_account(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'CA', 'tax_registration_scheme' => 'GST_HST', 'tax_registration_number' => '123456789RT0001']);

        $this->assertSame('canada_gst_hst', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));

        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }
}
