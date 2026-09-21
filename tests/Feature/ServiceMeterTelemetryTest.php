<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\ServiceAsset;
use App\Models\ServiceAssetMeterReading;
use App\Models\ServiceMaintenanceSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceMeterTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_meter_readings_are_idempotent_monotonic_and_surface_due_schedules(): void
    {
        $company = Company::create(['name' => 'Meter Telemetry Co', 'code' => 'METER-TELEMETRY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $asset = ServiceAsset::create([
            'company_id' => $company->id, 'asset_no' => 'ASSET-METER-001', 'name' => 'Metered compressor',
            'status' => 'active', 'meter_value' => 100,
        ]);
        $schedule = ServiceMaintenanceSchedule::create([
            'company_id' => $company->id, 'asset_id' => $asset->id, 'name' => 'Compressor service',
            'frequency_days' => 30, 'next_due' => now()->addYear()->toDateString(),
            'meter_interval' => 50, 'next_meter_due' => 150, 'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['service:write', 'service:read']);
        $payload = [
            'external_reference' => 'meter-reading-001', 'meter_value' => 150,
            'occurred_at' => '2026-09-20 10:00:00', 'source' => 'iot-gateway',
            'metadata' => ['device_id' => 'compressor-1'],
        ];

        $this->postJson('/api/service/assets/'.$asset->id.'/meter-readings', $payload)
            ->assertCreated()->assertJsonPath('status', 'recorded')
            ->assertJsonPath('data.meter_value', '150.000000')
            ->assertJsonPath('due_schedules.0.id', $schedule->id);

        $this->assertSame(150.0, (float) $asset->fresh()->meter_value);
        $this->assertDatabaseHas('service_asset_meter_readings', [
            'company_id' => $company->id, 'asset_id' => $asset->id,
            'external_reference' => 'meter-reading-001', 'meter_value' => 150,
        ]);

        $this->postJson('/api/service/assets/'.$asset->id.'/meter-readings', $payload)
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->assertSame(1, ServiceAssetMeterReading::where('asset_id', $asset->id)->count());

        $this->postJson('/api/service/assets/'.$asset->id.'/meter-readings', array_merge($payload, [
            'external_reference' => 'meter-reading-older', 'meter_value' => 149,
        ]))->assertStatus(422)->assertJsonPath('message', 'Meter readings cannot move backwards; submit a correction through an approved asset workflow.');

        $this->getJson('/api/service/assets/'.$asset->id.'/meter-readings?per_page=10')
            ->assertOk()->assertJsonPath('data.0.external_reference', 'meter-reading-001');
    }

    public function test_service_asset_can_link_to_a_tenant_serial_for_traceability(): void
    {
        $company = Company::create(['name' => 'Serial Service Co', 'code' => 'SERIAL-SERVICE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Serial Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Serial Each', 'code' => 'SER-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Serial Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Serialized pump', 'quantity' => 0, 'status' => 1, 'tracking_type' => 'serial']);
        $serial = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'PUMP-SN-001', 'status' => 'issued']);

        Sanctum::actingAs($user, ['service:write', 'service:read']);
        $created = $this->postJson('/api/service/assets', [
            'asset_no' => 'ASSET-SERIAL-001', 'name' => 'Customer pump', 'inventory_serial_id' => $serial->id,
        ]);

        $created->assertCreated()->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.serial_no', 'PUMP-SN-001')->assertJsonPath('data.serial.id', $serial->id);
        $this->getJson('/api/service/assets?per_page=10')->assertOk()->assertJsonPath('data.0.serial.serial_no', 'PUMP-SN-001');
    }
}
