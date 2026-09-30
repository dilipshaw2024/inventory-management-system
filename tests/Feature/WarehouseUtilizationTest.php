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
    public function test_location_utilization_can_roll_up_nested_capacity(): void
    {
        $company = Company::create(['name' => 'Hierarchy Utilization Co', 'code' => 'UTIL-HIER']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'UTIL-HIER-MAIN']);
        $warehouse = $branch->warehouses()->create(['name' => 'Central', 'code' => 'UTIL-HIER-WH']);
        $zone = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Zone A', 'code' => 'UTIL-ZONE', 'type' => 'zone', 'capacity' => 500, 'is_active' => true]);
        InventoryLocation::create(['warehouse_id' => $warehouse->id, 'parent_id' => $zone->id, 'name' => 'Bin A1', 'code' => 'UTIL-BIN-A1', 'type' => 'bin', 'capacity' => 100, 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['warehouse:read']);

        $this->getJson('/api/integration/warehouse/location-utilization?type=zone&include_descendants=1')
            ->assertOk()
            ->assertJsonPath('meta.include_descendants', true)
            ->assertJsonPath('data.0.descendant_count', 1)
            ->assertJsonPath('data.0.subtree_capacity', 600)
            ->assertJsonPath('data.0.subtree_available_capacity', 600)
            ->assertJsonPath('data.0.subtree_utilization_percent', 0);
    }

    public function test_utilization_snapshots_are_repeat_safe_and_filterable(): void
    {
        $company = Company::create(['name' => 'Utilization History Co', 'code' => 'UTIL-HISTORY']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'UTIL-HISTORY-MAIN']);
        $warehouse = $branch->warehouses()->create(['name' => 'Central', 'code' => 'UTIL-HISTORY-WH']);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Bin 1', 'code' => 'UTIL-HISTORY-BIN', 'type' => 'bin', 'capacity' => 80, 'is_active' => true]);
        $first = app(\App\Services\WarehouseUtilizationSnapshotService::class)->capture($company->id, '2026-09-28');
        $second = app(\App\Services\WarehouseUtilizationSnapshotService::class)->capture($company->id, '2026-09-28');
        $this->assertSame($first->first()->id, $second->first()->id);
        $this->assertDatabaseCount('warehouse_utilization_snapshots', 1);

        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['warehouse:read']);
        $this->getJson('/api/integration/warehouse/location-utilization-snapshots?location_id='.$location->id.'&from=2026-09-28&to=2026-09-28')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_id', $location->id)
            ->assertJsonPath('data.0.capacity', 80)
            ->assertJsonPath('data.0.utilization_percent', 0);
    }

}
