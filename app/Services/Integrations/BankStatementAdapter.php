<?php

namespace App\Services\Integrations;

interface BankStatementAdapter
{
    public function key(): string;

    /** @return array{bank_account_id:int, transaction_date:string, amount:numeric, reference?:string|null, description?:string|null, external_reference?:string|null} */
    public function normalize(array $payload): array;
}
