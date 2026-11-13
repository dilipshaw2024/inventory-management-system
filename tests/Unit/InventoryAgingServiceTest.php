<?php

namespace Tests\Unit;

use App\Services\InventoryAgingService;
use Tests\TestCase;

class InventoryAgingServiceTest extends TestCase
{
    public function test_age_buckets_are_deterministic_and_cover_boundaries(): void
    {
        $service = new InventoryAgingService();

        $this->assertSame(['0-30', '31-60', '61-90', '91-180', '181-365', '366+'], $service->buckets());
        $this->assertSame('0-30', $service->bucketForAge(0));
        $this->assertSame('0-30', $service->bucketForAge(30));
        $this->assertSame('31-60', $service->bucketForAge(31));
        $this->assertSame('61-90', $service->bucketForAge(90));
        $this->assertSame('91-180', $service->bucketForAge(180));
        $this->assertSame('181-365', $service->bucketForAge(365));
        $this->assertSame('366+', $service->bucketForAge(366));
    }
}
