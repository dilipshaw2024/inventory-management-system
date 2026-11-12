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
use App\Models\MaintenanceOrder;
use App\Models\ServiceTechnician;
use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
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

    public function test_technician_availability_respects_calendar_and_approved_leave(): void
    {
        $company = Company::create(['name' => 'Technician Calendar Co', 'code' => 'TECH-CALENDAR']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $technicianUser = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $technician = ServiceTechnician::create([
            'company_id' => $company->id, 'user_id' => $technicianUser->id, 'employee_code' => 'TECH-001',
            'is_available' => true, 'skills' => ['electrical'], 'working_calendar' => ['weekend_days' => [0, 6], 'holidays' => [], 'shift_start' => '08:00', 'shift_end' => '17:00'],
        ]);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'TECH-ASSET-001', 'name' => 'Service asset', 'status' => 'active']);
        $employee = HrEmployee::create(['company_id' => $company->id, 'employee_no' => 'EMP-TECH-001', 'user_id' => $technicianUser->id, 'first_name' => 'Service', 'status' => 'active']);
        $leaveType = HrLeaveType::create(['company_id' => $company->id, 'code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_entitlement' => 20]);

        Sanctum::actingAs($admin, ['service:read', 'service:write']);
        $this->getJson('/api/service/technicians/availability?start_at=2026-09-21%2010:00:00&end_at=2026-09-21%2012:00:00')
            ->assertOk()->assertJsonPath('data.0.id', $technician->id);
        $this->getJson('/api/service/technicians/availability?start_at=2026-09-21%2010:00:00&end_at=2026-09-21%2012:00:00&required_skills[]=electrical')
            ->assertOk()->assertJsonPath('data.0.id', $technician->id);
        $this->getJson('/api/service/technicians/availability?start_at=2026-09-21%2010:00:00&end_at=2026-09-21%2012:00:00&required_skills[]=refrigeration')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/service/technicians/availability?start_at=2026-09-19%2010:00:00&end_at=2026-09-19%2012:00:00')
            ->assertOk()->assertJsonCount(0, 'data');

        HrLeaveRequest::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-21', 'days' => 1, 'status' => 'approved']);
        $this->getJson('/api/service/technicians/availability?start_at=2026-09-21%2010:00:00&end_at=2026-09-21%2012:00:00')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/service/orders', [
            'asset_id' => $asset->id, 'maintenance_type' => 'corrective', 'scheduled_date' => '2026-09-21',
            'scheduled_start_at' => '2026-09-21 10:00:00', 'scheduled_end_at' => '2026-09-21 12:00:00', 'assigned_to' => $technicianUser->id,
        ])->assertStatus(422)->assertJsonPath('message', 'The technician is outside working hours, on a non-working day, or on approved leave for this window.');
    }

    public function test_service_order_feed_supports_scheduled_window_filters(): void
    {
        $company = Company::create(['name' => 'Service Calendar Feed Co', 'code' => 'SERVICE-CALENDAR-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'CAL-FEED-ASSET', 'name' => 'Calendar feed asset', 'status' => 'active']);
        MaintenanceOrder::create([
            'company_id' => $company->id, 'order_no' => 'CAL-FEED-IN', 'asset_id' => $asset->id,
            'maintenance_type' => 'preventive', 'status' => 'planned', 'scheduled_date' => '2026-09-22',
            'scheduled_start_at' => '2026-09-22 10:00:00', 'scheduled_end_at' => '2026-09-22 11:00:00',
        ]);
        MaintenanceOrder::create([
            'company_id' => $company->id, 'order_no' => 'CAL-FEED-OUT', 'asset_id' => $asset->id,
            'maintenance_type' => 'preventive', 'status' => 'planned', 'scheduled_date' => '2026-09-30',
            'scheduled_start_at' => '2026-09-30 10:00:00', 'scheduled_end_at' => '2026-09-30 11:00:00',
        ]);

        Sanctum::actingAs($user, ['service:read']);
        $this->getJson('/api/service/orders?scheduled_from=2026-09-22%2000:00:00&scheduled_to=2026-09-23%2023:59:59')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_no', 'CAL-FEED-IN');
        $this->getJson('/api/service/orders?scheduled_from=2026-09-23&scheduled_to=2026-09-22')
            ->assertStatus(422);
    }

    public function test_dispatch_calendar_groups_orders_and_reports_technician_workload(): void
    {
        $company = Company::create(['name' => 'Service Calendar API Co', 'code' => 'SERVICE-CALENDAR-API']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $technicianUser = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $technician = ServiceTechnician::create(['company_id' => $company->id, 'user_id' => $technicianUser->id, 'employee_code' => 'CAL-TECH-001', 'is_available' => true, 'working_calendar' => ['weekend_days' => [0, 6], 'holidays' => [], 'shift_start' => '08:00', 'shift_end' => '17:00']]);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'CAL-API-ASSET', 'name' => 'Calendar API asset', 'status' => 'active']);
        MaintenanceOrder::create(['company_id' => $company->id, 'order_no' => 'CAL-API-001', 'asset_id' => $asset->id, 'maintenance_type' => 'corrective', 'status' => 'planned', 'scheduled_date' => '2026-09-22', 'scheduled_start_at' => '2026-09-22 10:00:00', 'scheduled_end_at' => '2026-09-22 12:00:00', 'estimated_hours' => 2, 'assigned_to' => $technicianUser->id]);
        Sanctum::actingAs($user, ['service:read']);
        $this->getJson('/api/service/dispatch/calendar?start_date=2026-09-22&end_date=2026-09-23')
            ->assertOk()->assertJsonPath('data.days.0.orders.0.order_no', 'CAL-API-001')->assertJsonPath('data.technicians.0.order_count', 1)->assertJsonPath('data.technicians.0.estimated_hours', 2)->assertJsonPath('data.technicians.0.capacity_hours', 18)->assertJsonPath('data.technicians.0.utilization_percent', 11.11)->assertJsonPath('data.technicians.0.daily.0.date', '2026-09-22')->assertJsonPath('data.technicians.0.daily.0.estimated_hours', 2)->assertJsonPath('data.technicians.0.daily.0.utilization_percent', 22.22)->assertJsonPath('data.unassigned_order_count', 0);
        $this->getJson('/api/service/dispatch/calendar?start_date=2026-09-01&end_date=2026-10-15')->assertStatus(422);
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

    public function test_serial_service_handoffs_are_audited_idempotent_and_conflict_safe(): void
    {
        $company = Company::create(['name' => 'Serial Handoff Co', 'code' => 'SERIAL-HANDOFF']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Handoff Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Handoff Each', 'code' => 'HAND-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Handoff Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Handoff Pump', 'quantity' => 0, 'status' => 1, 'tracking_type' => 'serial']);
        $serial = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'HAND-SN-001', 'status' => 'available']);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'HAND-ASSET-001', 'name' => 'Handoff asset', 'product_id' => $product->id, 'status' => 'active']);
        $otherAsset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'HAND-ASSET-002', 'name' => 'Other asset', 'product_id' => $product->id, 'status' => 'active']);

        Sanctum::actingAs($user, ['service:write', 'service:read']);
        $install = $this->postJson('/api/service/assets/'.$asset->id.'/serial-handoffs', [
            'action' => 'installed', 'inventory_serial_id' => $serial->id, 'effective_at' => '2026-09-24 10:00:00',
            'location' => 'Customer site A', 'external_reference' => 'handoff-install-001',
        ])->assertCreated()->assertJsonPath('data.action', 'installed');
        $this->assertSame('issued', $serial->fresh()->status);
        $this->assertSame($serial->id, $asset->fresh()->inventory_serial_id);

        $this->postJson('/api/service/assets/'.$asset->id.'/serial-handoffs', [
            'action' => 'installed', 'inventory_serial_id' => $serial->id, 'effective_at' => '2026-09-24 10:00:00',
            'external_reference' => 'handoff-install-001',
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->postJson('/api/service/assets/'.$otherAsset->id.'/serial-handoffs', [
            'action' => 'installed', 'inventory_serial_id' => $serial->id, 'effective_at' => '2026-09-24 11:00:00',
        ])->assertStatus(422)->assertJsonPath('message', 'This inventory serial is already installed on another active service asset.');

        $this->postJson('/api/service/assets/'.$asset->id.'/serial-handoffs', [
            'action' => 'removed', 'inventory_serial_id' => $serial->id, 'effective_at' => '2026-09-25 10:00:00',
            'notes' => 'Returned for service.',
        ])->assertCreated()->assertJsonPath('data.action', 'removed');
        $this->assertSame('returned', $serial->fresh()->status);
        $this->assertNull($asset->fresh()->inventory_serial_id);
        $this->getJson('/api/service/assets/'.$asset->id.'/serial-handoffs?per_page=10')
            ->assertOk()->assertJsonPath('data.0.action', 'installed')->assertJsonPath('data.1.action', 'removed');
        $this->assertSame(2, ServiceAsset::findOrFail($asset->id)->serialHandoffs()->count());
    }
}
