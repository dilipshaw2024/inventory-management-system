<?php

namespace App\Services\Integrations;

use App\Models\EInvoiceSubmission;

interface EInvoiceSubmitter
{
    /** Submit a prepared envelope and return provider status metadata. */
    public function submit(EInvoiceSubmission $submission): array;
}
