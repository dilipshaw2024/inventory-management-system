<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\Integrations\RegionalCompliancePackRegistry;
use App\Services\Integrations\Ubl21EInvoiceProvider;
use App\Services\Integrations\BrazilNfe55EInvoiceProvider;
use App\Services\Integrations\IndiaGstEInvoiceProvider;
use App\Services\Integrations\MexicoCfdi40EInvoiceProvider;
use App\Services\Integrations\SaudiZatcaEInvoiceProvider;
use App\Services\Integrations\UnitedArabEmiratesPeppolEInvoiceProvider;
use App\Services\Integrations\JapanQualifiedInvoiceEInvoiceProvider;
use App\Services\Integrations\SouthKoreaElectronicTaxInvoiceProvider;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Product;
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
        $company = new Company(['country_code' => 'ZZ', 'tax_registration_scheme' => 'TAX', 'tax_registration_number' => '123']);

        $this->assertSame('generic', $registry->forCompany($company)->key());
        $this->assertContains('india_gst', $registry->keys());
        $this->assertContains('united_states_sales_tax', $registry->keys());
        $this->assertContains('brazil_tax', $registry->keys());
        $this->assertContains('saudi_vat', $registry->keys());
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

    public function test_uae_company_selects_vat_pack_and_validates_trn(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'AE', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => '100123456789003']);

        $this->assertSame('uae_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame(['AE_HS'], $registry->forCompany($company)->context($company)['rules']['classification_schemes']);
        $company->tax_registration_number = '123';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_australian_company_selects_gst_pack_and_normalizes_abn(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'AU', 'tax_registration_scheme' => 'GST', 'tax_registration_number' => '51 824 753 556']);

        $this->assertSame('australia_gst', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame('51824753556', $registry->forCompany($company)->context($company)['tax_registration_number']);
        $company->tax_registration_number = '123';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_singapore_company_selects_gst_pack_and_normalizes_uen(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'SG', 'tax_registration_scheme' => 'GST', 'tax_registration_number' => ' 201912345Z ']);

        $this->assertSame('singapore_gst', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame('201912345Z', $registry->forCompany($company)->context($company)['tax_registration_number']);
        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_south_african_company_selects_vat_pack_and_validates_number(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'ZA', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => '4123456789']);

        $this->assertSame('south_africa_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame(['ZA_HS'], $registry->forCompany($company)->context($company)['rules']['classification_schemes']);
        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_new_zealand_company_selects_gst_pack_and_validates_ird_number(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'NZ', 'tax_registration_scheme' => 'GST', 'tax_registration_number' => '12345678']);

        $this->assertSame('new_zealand_gst', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame(['NZ_HS'], $registry->forCompany($company)->context($company)['rules']['classification_schemes']);
        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_mexico_company_selects_vat_pack_and_normalizes_rfc(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'MX', 'tax_registration_scheme' => 'IVA', 'tax_registration_number' => ' ABC123456AB1 ']);
        $this->assertSame('mexico_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame('ABC123456AB1', $registry->forCompany($company)->context($company)['tax_registration_number']);
        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_south_korea_company_selects_vat_pack_and_validates_business_number(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'KR', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => '1234567890']);
        $this->assertSame('south_korea_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame(['KR_HS'], $registry->forCompany($company)->context($company)['rules']['classification_schemes']);
        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_china_company_selects_vat_pack_and_validates_unified_social_credit_code(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'CN', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => '91310000MA1K12345X']);
        $pack = $registry->forCompany($company);

        $this->assertSame('china_vat', $pack->key());
        $this->assertSame([], $pack->validateCompany($company));
        $this->assertSame('CNY', $pack->context($company)['currency']);
        $this->assertSame(['CN_HS'], $pack->context($company)['rules']['classification_schemes']);

        $company->tax_registration_number = 'invalid';
        $this->assertNotEmpty($pack->validateCompany($company));
    }

    public function test_swiss_company_selects_vat_pack_and_normalizes_uid_mwst(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company([
            'country_code' => 'CH',
            'tax_registration_scheme' => 'MWST',
            'tax_registration_number' => 'CHE-123.456.789 MWST',
        ]);
        $pack = $registry->forCompany($company);

        $this->assertSame('switzerland_vat', $pack->key());
        $this->assertSame([], $pack->validateCompany($company));
        $this->assertSame('CHF', $pack->context($company)['currency']);
        $this->assertSame(8.1, $pack->context($company)['rules']['vat_rates']['standard']);

        $company->tax_registration_number = 'CHE-123.456.789 VAT';
        $this->assertNotEmpty($pack->validateCompany($company));
    }

    public function test_norwegian_company_selects_vat_pack_and_ubl_provider(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company([
            'country_code' => 'NO',
            'tax_registration_scheme' => 'MVA',
            'tax_registration_number' => '984661185 MVA',
        ]);
        $pack = $registry->forCompany($company);

        $this->assertSame('norway_vat', $pack->key());
        $this->assertSame([], $pack->validateCompany($company));
        $this->assertSame('NOK', $pack->context($company)['currency']);
        $this->assertSame('peppol_ehf', $pack->context($company)['rules']['e_invoice_profile']);
        $this->assertSame('ubl_2_1', app(\App\Services\Integrations\EInvoiceProviderRegistry::class)->forCompany($company)->key());

        $company->tax_registration_number = '984661184 MVA';
        $this->assertNotEmpty($pack->validateCompany($company));
        $company->tax_registration_number = '12345678';
        $this->assertNotEmpty($pack->validateCompany($company));
    }

    public function test_japan_company_selects_qualified_invoice_pack(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'JP', 'tax_registration_scheme' => 'JCT', 'tax_registration_number' => ' T1234567890123 ']);
        $this->assertSame('japan_qualified_invoice', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame('T1234567890123', $registry->forCompany($company)->context($company)['tax_registration_number']);
        $company->tax_registration_number = '123';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_united_states_company_selects_sales_tax_pack(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'US', 'tax_registration_scheme' => 'EIN', 'tax_registration_number' => '12-3456789']);

        $this->assertSame('united_states_sales_tax', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertTrue($registry->forCompany($company)->context($company)['rules']['requires_nexus_provider']);
    }

    public function test_brazil_company_selects_tax_pack_and_normalizes_cnpj(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['country_code' => 'BR', 'tax_registration_scheme' => 'CNPJ', 'tax_registration_number' => '12.345.678/0001-95']);

        $this->assertSame('brazil_tax', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertSame('12345678000195', $registry->forCompany($company)->context($company)['tax_registration_number']);
        $company->tax_registration_number = '123';
        $this->assertNotEmpty($registry->forCompany($company)->validateCompany($company));
    }

    public function test_ubl_provider_builds_portable_invoice_document(): void
    {
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 2, 'unit_price' => 12.5, 'selling_price' => 25, 'tax_rate' => 20]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'INV-42', 'date' => '2026-11-13', 'currency_code' => 'EUR', 'subtotal_amount' => 25, 'tax_amount' => 5, 'total_amount' => 30]);
        $invoice->setRelation('company', null);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'ACME', 'tax_number' => 'EU123']));

        $payload = (new Ubl21EInvoiceProvider())->prepare($invoice);

        $this->assertSame('ubl_2_1', (new Ubl21EInvoiceProvider())->key());
        $this->assertSame('UBL-Invoice-2.1', $payload['schema']);
        $this->assertSame('INV-42', $payload['invoice']['id']);
        $this->assertSame('EUR', $payload['invoice']['document_currency_code']);
        $this->assertSame('S', $payload['lines'][0]['item']['classified_tax_category']);
        $this->assertSame('ACME', $payload['customer_party']['name']);
        $this->assertStringContainsString('>INV-42</cbc:ID>', $payload['xml']);
        $this->assertStringContainsString('currencyID="EUR">30.00</cbc:PayableAmount>', $payload['xml']);
        $this->assertStringContainsString('>S</cbc:ID>', $payload['xml']);
        $this->assertStringContainsString('>20.00</cbc:Percent>', $payload['xml']);
    }

    public function test_brazil_nfe_provider_builds_hashed_model_55_payload(): void
    {
        $company = new Company(['name' => 'Brazil Seller', 'tax_registration_number' => '12.345.678/0001-95']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9', 'hsn_code' => '8471']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 2, 'unit_price' => 12.5, 'selling_price' => 25, 'tax_rate' => 18]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'NF-42', 'date' => '2026-11-13', 'subtotal_amount' => 25, 'tax_amount' => 4.5, 'total_amount' => 29.5]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new \App\Models\Customer(['name' => 'ACME', 'tax_number' => '12345678901']));

        $payload = (new BrazilNfe55EInvoiceProvider())->prepare($invoice);

        $this->assertSame('nfe_55_brazil', (new BrazilNfe55EInvoiceProvider())->key());
        $this->assertSame('NF-e-4.00', $payload['schema']);
        $this->assertSame('55', $payload['model']);
        $this->assertSame('NF-42', $payload['ide']['nNF']);
        $this->assertSame('8471', $payload['det'][0]['prod']['NCM']);
        $this->assertSame('12345678901', $payload['dest']['CNPJ_CPF']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }

    public function test_india_gst_provider_builds_irp_payload_with_integrity_hash(): void
    {
        $company = new Company(['name' => 'India Seller', 'tax_registration_number' => '27ABCDE1234F1Z5']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9', 'hsn_code' => '8471']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 2, 'unit_price' => 12.5, 'selling_price' => 25, 'tax_rate' => 18, 'tax_amount' => 4.5]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'INV-42', 'date' => '2026-11-13', 'subtotal_amount' => 25, 'tax_amount' => 4.5, 'total_amount' => 29.5]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new \App\Models\Customer(['name' => 'ACME', 'tax_number' => '29ABCDE1234F1Z5']));

        $payload = (new IndiaGstEInvoiceProvider())->prepare($invoice);

        $this->assertSame('india_gst_irp', (new IndiaGstEInvoiceProvider())->key());
        $this->assertSame('GST-India-eInvoice-1.1', $payload['schema']);
        $this->assertSame('27ABCDE1234F1Z5', $payload['SellerDtls']['Gstin']);
        $this->assertSame('8471', $payload['ItemList'][0]['HsnCd']);
        $this->assertSame(4.5, $payload['ItemList'][0]['TaxAmount']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }

    public function test_mexico_cfdi_provider_builds_version_40_payload_with_integrity_hash(): void
    {
        $company = new Company(['name' => 'Mexico Seller', 'tax_registration_number' => 'ABC123456AB1']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9', 'hsn_code' => '8471']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 2, 'unit_price' => 12.5, 'selling_price' => 25, 'tax_rate' => 16, 'tax_amount' => 4]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'CFDI-42', 'date' => '2026-11-13', 'currency_code' => 'MXN', 'subtotal_amount' => 25, 'tax_amount' => 4, 'total_amount' => 29]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new \App\Models\Customer(['name' => 'ACME', 'tax_number' => 'XAXX010101000']));

        $payload = (new MexicoCfdi40EInvoiceProvider())->prepare($invoice);

        $this->assertSame('mexico_cfdi_4_0', (new MexicoCfdi40EInvoiceProvider())->key());
        $this->assertSame('CFDI-4.0', $payload['schema']);
        $this->assertSame('4.0', $payload['Version']);
        $this->assertSame('ABC123456AB1', $payload['Emisor']['Rfc']);
        $this->assertSame('8471', $payload['Conceptos'][0]['ClaveProdServ']);
        $this->assertSame('002', $payload['Conceptos'][0]['Impuestos']['Traslados'][0]['Impuesto']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }

    public function test_saudi_company_selects_zatca_pack_and_provider_builds_phase_2_payload(): void
    {
        $registry = app(RegionalCompliancePackRegistry::class);
        $company = new Company(['name' => 'Saudi Seller', 'country_code' => 'SA', 'tax_registration_scheme' => 'VAT', 'tax_registration_number' => '312345678901233']);
        $this->assertSame('saudi_vat', $registry->forCompany($company)->key());
        $this->assertSame([], $registry->forCompany($company)->validateCompany($company));
        $this->assertTrue($registry->forCompany($company)->context($company)['rules']['requires_xml']);

        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9', 'hsn_code' => '8471']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 2, 'unit_price' => 10, 'selling_price' => 20, 'tax_rate' => 15, 'tax_amount' => 3]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'company_id' => 7, 'invoice_no' => 'SA-42', 'date' => '2026-11-13', 'currency_code' => 'SAR', 'subtotal_amount' => 20, 'tax_amount' => 3, 'total_amount' => 23]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Buyer', 'tax_number' => '312345678901233']));
        $payload = (new SaudiZatcaEInvoiceProvider())->prepare($invoice);

        $this->assertSame('saudi_zatca_phase_2', (new SaudiZatcaEInvoiceProvider())->key());
        $this->assertSame('ZATCA_PHASE_2_UBL_2_1', $payload['schema']);
        $this->assertSame('312345678901233', $payload['seller']['vat_number']);
        $this->assertSame('S', $payload['lines'][0]['tax_category']);
        $this->assertTrue($payload['zatca']['clearance_or_reporting_required']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }

    public function test_uae_provider_builds_structured_peppol_profile_with_aed_defaults(): void
    {
        $company = new Company(['name' => 'UAE Seller', 'tax_registration_number' => '100123456789003']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'tax_rate' => 5, 'tax_amount' => 5]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'AE-42', 'date' => '2026-11-13', 'subtotal_amount' => 100, 'tax_amount' => 5, 'total_amount' => 105]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Buyer', 'tax_number' => '100123456789003']));
        $payload = (new UnitedArabEmiratesPeppolEInvoiceProvider())->prepare($invoice);

        $this->assertSame('uae_peppol_einvoice', (new UnitedArabEmiratesPeppolEInvoiceProvider())->key());
        $this->assertSame('UAE_EINVOICE_UBL_2_1', $payload['schema']);
        $this->assertSame('AED', $payload['invoice']['document_currency_code']);
        $this->assertTrue($payload['uae']['requires_accredited_service_provider']);
        $this->assertStringContainsString('PayableAmount', $payload['xml']);
    }

    public function test_japan_provider_builds_qualified_invoice_payload_with_hash(): void
    {
        $company = new Company(['name' => 'Japan Seller', 'tax_registration_number' => 'T1234567890123']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'tax_rate' => 10, 'tax_amount' => 10]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'JP-42', 'date' => '2026-11-13', 'subtotal_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Buyer', 'tax_number' => 'T9876543210987']));

        $payload = (new JapanQualifiedInvoiceEInvoiceProvider())->prepare($invoice);

        $this->assertSame('japan_qualified_invoice', (new JapanQualifiedInvoiceEInvoiceProvider())->key());
        $this->assertSame('JAPAN_QUALIFIED_INVOICE', $payload['schema']);
        $this->assertSame('T1234567890123', $payload['seller']['qualified_invoice_registration_number']);
        $this->assertTrue($payload['japan']['qualified_invoice_display_required']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }

    public function test_south_korea_provider_builds_e_tax_invoice_payload_with_hash(): void
    {
        $company = new Company(['name' => 'Korea Seller', 'tax_registration_number' => '1234567890']);
        $product = new Product(['id' => 9, 'name' => 'Widget', 'sku' => 'W-9']);
        $line = new InvoiceDetail(['id' => 4, 'product_id' => 9, 'selling_qty' => 1, 'unit_price' => 1000, 'selling_price' => 1000, 'tax_rate' => 10, 'tax_amount' => 100]);
        $line->setRelation('product', $product);
        $invoice = new Invoice(['id' => 42, 'invoice_no' => 'KR-42', 'date' => '2026-11-13', 'subtotal_amount' => 1000, 'tax_amount' => 100, 'total_amount' => 1100]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('invoice_details', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Buyer', 'tax_number' => '9876543210']));

        $payload = (new SouthKoreaElectronicTaxInvoiceProvider())->prepare($invoice);

        $this->assertSame('south_korea_e_tax_invoice', (new SouthKoreaElectronicTaxInvoiceProvider())->key());
        $this->assertSame('KOREA_E_TAX_INVOICE', $payload['schema']);
        $this->assertSame('1234567890', $payload['supplier']['business_registration_number']);
        $this->assertTrue($payload['south_korea']['electronic_tax_invoice_required']);
        $this->assertSame(64, strlen($payload['payload_hash']));
    }
}
