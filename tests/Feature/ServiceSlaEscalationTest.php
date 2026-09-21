<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceSlaEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_breached_request_escalates_once_per_day_and_notifies_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:00:00'));
        $company = Company::create(['name' => 'SLA Escalation Co', 'code' => 'SLA-ESCALATION']);
        $assignee = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $request = ServiceRequest::create([
            'company_id' => $company->id, 'request_no' => 'SR-SLA-ESCALATION', 'description' => 'Urgent service request',
            'priority' => 'urgent', 'status' => 'assigned', 'assigned_to' => $assignee->id,
            'assigned_at' => Carbon::parse('2026-09-17 09:00:00'), 'response_due_at' => Carbon::parse('2026-09-17 08:00:00'),
        ]);

        $this->artisan('erp:service:sla-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'sla_escalation_level' => 1]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $assignee->id, 'notifiable_type' => User::class]);
        $this->assertSame(1, $assignee->notifications()->whereJsonContains('data->request_id', $request->id)->count());

        Sanctum::actingAs($assignee, ['service:read']);
        $this->getJson('/api/service/requests?sla_escalation_level=1&sla_escalated=1')
            ->assertOk()->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.sla_escalation_level', 1)
            ->assertJsonPath('data.0.sla_status', 'breached');

        $this->artisan('erp:service:sla-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'sla_escalation_level' => 1]);
        $this->assertSame(1, $assignee->notifications()->whereJsonContains('data->request_id', $request->id)->count());
        Carbon::setTestNow();
    }
}
