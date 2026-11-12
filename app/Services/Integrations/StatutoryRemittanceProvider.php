<?php

namespace App\Services\Integrations;

interface StatutoryRemittanceProvider
{
    public function key(): string;

    public function submit(array $package): array;
}
