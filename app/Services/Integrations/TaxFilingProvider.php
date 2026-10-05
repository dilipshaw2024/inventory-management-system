<?php

namespace App\Services\Integrations;

use App\Models\TaxFiling;

interface TaxFilingProvider
{
    public function key(): string;
    public function submit(TaxFiling $filing): array;
}
