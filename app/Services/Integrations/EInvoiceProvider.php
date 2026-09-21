<?php

namespace App\Services\Integrations;

use App\Models\Invoice;

interface EInvoiceProvider
{
    public function key(): string;

    /** Build a jurisdiction-neutral, provider-ready document. No network call is made here. */
    public function prepare(Invoice $invoice): array;
}
