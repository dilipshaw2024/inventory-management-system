<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class EInvoiceProviderRegistry
{
    /** @var array<string, EInvoiceProvider> */
    private array $providers = [];

    /** @param iterable<EInvoiceProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        $this->register(new GenericEInvoiceProvider());
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
}
