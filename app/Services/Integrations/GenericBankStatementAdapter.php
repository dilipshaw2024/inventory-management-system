<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class GenericBankStatementAdapter implements BankStatementAdapter
{
    public function key(): string { return 'generic'; }

    public function normalize(array $payload): array
    {
        $accountId = $payload['bank_account_id'] ?? $payload['account_id'] ?? null;
        $date = $payload['transaction_date'] ?? $payload['date'] ?? null;
        $amount = $payload['amount'] ?? null;
        if (!$accountId || !$date || $amount === null) throw new InvalidArgumentException('Bank statement payload must include bank_account_id, transaction_date, and amount.');
        return [
            'bank_account_id' => (int) $accountId,
            'transaction_date' => (string) $date,
            'amount' => $amount,
            'reference' => $payload['reference'] ?? $payload['ref'] ?? null,
            'description' => $payload['description'] ?? $payload['narration'] ?? null,
            'external_reference' => $payload['external_reference'] ?? $payload['transaction_id'] ?? $payload['id'] ?? null,
        ];
    }
}
