<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class StatutoryRemittanceProviderRegistry
{
    private array $providers = [];

    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(StatutoryRemittanceProvider $provider): void
    {
        $this->providers[strtolower(trim($provider->key()))] = $provider;
    }

    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function resolve(string $key): StatutoryRemittanceProvider
    {
        $key = strtolower(trim($key));
        if (!isset($this->providers[$key])) throw new InvalidArgumentException('Unsupported statutory remittance provider: '.$key);
        return $this->providers[$key];
    }
}
