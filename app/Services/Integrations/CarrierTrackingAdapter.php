<?php

namespace App\Services\Integrations;

interface CarrierTrackingAdapter
{
    public function key(): string;

    /**
     * Convert a provider payload into the ERP tracking-event contract.
     *
     * @return array{delivery_id:int, external_reference?:string|null, carrier_status:string, event_at:string, event_location?:string|null, description?:string|null}
     */
    public function normalize(array $payload): array;
}
