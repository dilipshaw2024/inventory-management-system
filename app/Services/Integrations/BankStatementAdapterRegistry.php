<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class BankStatementAdapterRegistry
{
    /** @var array<string, BankStatementAdapter> */
    private array $adapters = [];

    /** @param iterable<BankStatementAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        $this->register(new GenericBankStatementAdapter());
        foreach ($adapters as $adapter) $this->register($adapter);
    }

    public function register(BankStatementAdapter $adapter): void { $this->adapters[$adapter->key()] = $adapter; }

    public function resolve(string $key): BankStatementAdapter
    {
        $key = strtolower(trim($key));
        if (!isset($this->adapters[$key])) throw new InvalidArgumentException('Unsupported bank statement provider: '.$key);
        return $this->adapters[$key];
    }
}
