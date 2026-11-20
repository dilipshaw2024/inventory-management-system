<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EInvoiceRetryCommandTest extends TestCase
{
    public function test_e_invoice_retry_command_is_registered_with_operational_controls(): void
    {
        $command = Artisan::all()['erp:accounting:retry-e-invoices'] ?? null;

        $this->assertNotNull($command);
        $this->assertTrue($command->getDefinition()->hasOption('company'));
        $this->assertTrue($command->getDefinition()->hasOption('provider'));
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
    }
}
