<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_health_check_reports_the_isolated_erp_schema_as_healthy(): void
    {
        $this->artisan('erp:system:health', ['--strict' => true, '--json' => true])
            ->expectsOutputToContain('"status": "healthy"')
            ->assertExitCode(0);
    }

    public function test_http_readiness_endpoint_returns_a_minimal_healthy_payload(): void
    {
        $this->getJson('/health/erp')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure(['status', 'checked_at']);
    }
}
