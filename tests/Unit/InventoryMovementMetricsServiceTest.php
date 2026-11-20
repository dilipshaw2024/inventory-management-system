<?php

namespace Tests\Unit;

use App\Services\InventoryMovementMetricsService;
use Tests\TestCase;

class InventoryMovementMetricsServiceTest extends TestCase
{
    public function test_zero_velocity_threshold_preserves_no_issue_behavior(): void
    {
        $service = app(InventoryMovementMetricsService::class);

        $this->assertTrue($service->isSlowMoving(0, 90, 0));
        $this->assertFalse($service->isSlowMoving(0.000002, 90, 0));
    }

    public function test_positive_velocity_threshold_classifies_average_daily_issue_quantity(): void
    {
        $service = app(InventoryMovementMetricsService::class);

        $this->assertTrue($service->isSlowMoving(90, 90, 1));
        $this->assertTrue($service->isSlowMoving(45, 90, 0.5));
        $this->assertFalse($service->isSlowMoving(91, 90, 1));
    }
}
