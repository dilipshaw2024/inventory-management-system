<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class CarrierTrackingAdapterRegistry
{
    /** @var array<string, CarrierTrackingAdapter> */
    private array $adapters = [];

    /** @param iterable<CarrierTrackingAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        $this->register(new GenericCarrierTrackingAdapter());
        foreach ($adapters as $adapter) $this->register($adapter);
    }

    public function register(CarrierTrackingAdapter $adapter): void
    {
        $this->adapters[$adapter->key()] = $adapter;
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->adapters); }

    public function resolve(string $key): CarrierTrackingAdapter
    {
        $key = strtolower(trim($key));
        if (!isset($this->adapters[$key])) throw new InvalidArgumentException('Unsupported carrier tracking provider: '.$key);
        return $this->adapters[$key];
    }
}
