<?php

namespace Tests\Unit;

use App\Services\CarrierSlaPolicyService;
use App\Services\ErpSettingService;
use Mockery;
use Tests\TestCase;

class CarrierSlaPolicyServiceTest extends TestCase
{
    public function test_deadline_skips_weekends_and_uses_shift_hours(): void
    {
        $settings = Mockery::mock(ErpSettingService::class);
        $settings->shouldReceive('get')->with('branch_sla_calendars', [], 1)->andReturn([]);
        $settings->shouldReceive('get')->with('sla_calendar', null, 1)->andReturn(['weekend_days' => [0, 6], 'holidays' => [], 'shift_start' => '08:00', 'shift_end' => '17:00']);
        $service = new CarrierSlaPolicyService($settings);

        $deadline = $service->deadlineAt('2026-09-18 16:00:00', 2, 1);

        $this->assertSame('2026-09-21 09:00', $deadline->format('Y-m-d H:i'));
    }

    public function test_branch_carrier_target_overrides_company_target_case_insensitively(): void
    {
        $settings = Mockery::mock(ErpSettingService::class);
        $settings->shouldReceive('get')->with('branch_carrier_sla_hours', [], 1)->andReturn(['7' => ['Carrier One' => 24]]);
        $settings->shouldReceive('get')->with('carrier_sla_hours', [], 1)->andReturn(['Carrier One' => 48]);
        $service = new CarrierSlaPolicyService($settings);

        $this->assertSame(24.0, $service->hoursFor(1, 7, 'carrier one'));
        $this->assertSame(48.0, $service->hoursFor(1, 8, 'CARRIER ONE'));
    }
}
