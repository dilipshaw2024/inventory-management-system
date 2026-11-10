<?php

namespace App\Services\Integrations;

interface ExchangeRateProvider
{
    public function key(): string;

    /** @return list<array{from_currency:string,to_currency:string,rate:numeric,effective_date?:string,source_reference?:string,retrieved_at?:string}> */
    public function fetch(array $context = []): array;
}
