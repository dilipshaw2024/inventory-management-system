<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class TaxFilingProviderRegistry
{
    private array $providers = [];

    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(TaxFilingProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function resolve(string $key): TaxFilingProvider
    {
        $key = strtolower(trim($key));
        if (!isset($this->providers[$key])) throw new InvalidArgumentException('Unsupported tax filing provider: '.$key);
        return $this->providers[$key];
    }
}
