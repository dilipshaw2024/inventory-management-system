<?php

namespace App\Services\Integrations;

use App\Models\Company;
use InvalidArgumentException;

class RegionalCompliancePackRegistry
{
    /** @var array<string, RegionalCompliancePack> */
    private array $packs = [];

    /** @param iterable<RegionalCompliancePack> $packs */
    public function __construct(iterable $packs = [])
    {
        $this->register(new GenericRegionalCompliancePack());
        $this->register(new IndiaGstCompliancePack());
        $this->register(new EuropeanVatCompliancePack());
        $this->register(new UnitedKingdomVatCompliancePack());
        $this->register(new CanadianGstCompliancePack());
        foreach ($packs as $pack) $this->register($pack);
    }

    public function register(RegionalCompliancePack $pack): void
    {
        $this->packs[strtolower(trim($pack->key()))] = $pack;
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->packs); }

    public function resolve(string $key): RegionalCompliancePack
    {
        $key = strtolower(trim($key));
        if (!isset($this->packs[$key])) throw new InvalidArgumentException('Unsupported regional compliance pack: '.$key);
        return $this->packs[$key];
    }

    public function forCompany(Company $company, ?string $key = null): RegionalCompliancePack
    {
        if ($key !== null && trim($key) !== '') return $this->resolve($key);
        $country = strtoupper(trim((string) $company->country_code));
        $scheme = strtoupper(trim((string) $company->tax_registration_scheme));
        foreach ($this->packs as $pack) {
            if ($pack->key() !== 'generic' && in_array($country, $pack->countryCodes(), true)
                && ($scheme === '' || in_array($scheme, $pack->registrationSchemes(), true))) return $pack;
        }
        return $this->resolve('generic');
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        return array_values(array_map(fn (RegionalCompliancePack $pack): array => [
            'key' => $pack->key(), 'country_codes' => $pack->countryCodes(), 'registration_schemes' => $pack->registrationSchemes(),
        ], $this->packs));
    }
}
