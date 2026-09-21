<?php

namespace App\Services\Integrations;

interface BankStatementPoller
{
    /** @return list<array<string, mixed>> */
    public function fetch(int $bankAccountId, array $context = []): array;
}
