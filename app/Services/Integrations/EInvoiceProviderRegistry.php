<?php

namespace App\Services\Integrations;

use App\Models\Company;
use InvalidArgumentException;

class EInvoiceProviderRegistry
{
    /** @var list<string> */
    private const UBL_COUNTRIES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'GR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'NOR', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'GB', 'UK', 'AU', 'NZ', 'SG'];
    /** @var array<string, EInvoiceProvider> */
    private array $providers = [];

    /** @param iterable<EInvoiceProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        $this->register(new GenericEInvoiceProvider());
        $this->register(new Ubl21EInvoiceProvider());
        $this->register(new BrazilNfe55EInvoiceProvider());
        $this->register(new IndiaGstEInvoiceProvider());
        $this->register(new MexicoCfdi40EInvoiceProvider());
        $this->register(new SaudiZatcaEInvoiceProvider());
        $this->register(new UnitedArabEmiratesPeppolEInvoiceProvider());
        $this->register(new JapanQualifiedInvoiceEInvoiceProvider());
        $this->register(new SouthKoreaElectronicTaxInvoiceProvider());
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(EInvoiceProvider $provider): void { $this->providers[$provider->key()] = $provider; }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->providers); }

    public function supportsSubmission(string $key): bool
    {
        return $this->resolve($key) instanceof EInvoiceSubmitter;
    }

    public function resolve(string $key): EInvoiceProvider
    {
        $key = strtolower(trim($key));
        if (!isset($this->providers[$key])) throw new InvalidArgumentException('Unsupported e-invoice provider: '.$key);
        return $this->providers[$key];
    }

    /**
     * Resolve an explicit provider or choose the built-in regional adapter for
     * a company. Generic remains the safe backward-compatible fallback.
     */
    public function forCompany(Company $company, ?string $key = null): EInvoiceProvider
    {
        if ($key !== null && trim($key) !== '') return $this->resolve($key);

        $country = strtoupper(trim((string) $company->country_code));
        $regionalKey = match ($country) {
            'IN', 'IND' => 'india_gst_irp',
            'BR', 'BRA' => 'nfe_55_brazil',
            'MX', 'MEX' => 'mexico_cfdi_4_0',
            'SA', 'SAU' => 'saudi_zatca_phase_2',
            'AE', 'ARE' => 'uae_peppol_einvoice',
            'JP', 'JPN' => 'japan_qualified_invoice',
            'KR', 'KOR' => 'south_korea_e_tax_invoice',
            default => in_array($country, self::UBL_COUNTRIES, true) ? 'ubl_2_1' : 'generic',
        };

        return $this->resolve($regionalKey);
    }
}
