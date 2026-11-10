<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class ExchangeRateProviderRegistry
{
    /** @var array<string, ExchangeRateProvider> */
    private array $providers = [];

    /** @param iterable<ExchangeRateProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(ExchangeRateProvider $provider): void
    {
        $this->providers[strtolower(trim($provider->key()))] = $provider;
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->providers); }

    public function resolve(string $key): ExchangeRateProvider
    {
        $key = strtolower(trim($key));
        if (!isset($this->providers[$key])) throw new InvalidArgumentException('Unsupported exchange-rate provider: '.$key);
        return $this->providers[$key];
    }
}
