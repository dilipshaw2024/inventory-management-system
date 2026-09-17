<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseUtilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_utilization_is_company_scoped_and_reports_capacity(): void
    {
        $company = Company::create(['name' => 'Utilization Co', 'code' => 'UTIL-TEST']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'UTIL-MAIN']);
        $warehouse = $branch->warehouses()->create(['name' => 'Central', 'code' => 'UTIL-WH']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Bin 1', 'code' => 'UTIL-BIN', 'type' => 'bin', 'capacity' => 100, 'capacity_weight_kg' => 50, 'capacity_volume_m3' => 10, 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['warehouse:read']);

        $response = $this->getJson('/api/integration/warehouse/location-utilization?type=bin');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_id', $location->id)
            ->assertJsonPath('data.0.capacity', 100)
            ->assertJsonPath('data.0.occupied_quantity', 0)
            ->assertJsonPath('data.0.utilization_percent', 0)
            ->assertJsonPath('data.0.capacity_status', 'normal');
    }
}
