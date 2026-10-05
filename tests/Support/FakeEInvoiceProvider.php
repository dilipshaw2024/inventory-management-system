<?php

namespace Tests\Support;

use App\Models\Invoice;
use App\Services\Integrations\EInvoiceProvider;

class FakeEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string
    {
        return 'test-provider';
    }

    public function prepare(Invoice $invoice): array
    {
        return ['invoice_id' => $invoice->id, 'provider' => $this->key()];
    }
}
