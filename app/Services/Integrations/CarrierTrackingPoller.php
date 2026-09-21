<?php

namespace App\Services\Integrations;

interface CarrierTrackingPoller
{
    /** @return list<array<string, mixed>> */
    public function fetch(string $trackingNumber, array $context = []): array;
}
