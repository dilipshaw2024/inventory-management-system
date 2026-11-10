<?php

namespace Tests\Support;

use App\Models\TaxFiling;
use App\Services\Integrations\TaxFilingProvider;

class FakeTaxFilingProvider implements TaxFilingProvider
{
    public function key(): string
    {
        return 'test-tax-provider';
    }

    public function submit(TaxFiling $filing): array
    {
        return ['status' => 'accepted', 'filing_reference' => 'TEST-TAX-'.$filing->id, 'response' => ['provider' => $this->key()]];
    }
}
