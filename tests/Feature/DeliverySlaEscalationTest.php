<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ErpSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySlaEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_sla_alerts_escalate_once_per_day_and_are_company_scoped(): void
    {
        $company = Company::create(['name' => 'Delivery SLA Co', 'code' => 'DELIVERY-SLA']);
        $permission = Permission::create(['code' => 'sales.manage', 'name' => 'Manage sales', 'module' => 'sales']);
        $role = Role::create(['code' => 'delivery-sla-reader', 'name' => 'Delivery SLA reader', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $user->roles()->attach($role);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'SLA Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-DELIVERY-SLA', 'date' => now()->subDays(3)->toDateString(), 'status' => 'partially_delivered']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-DELIVERY-SLA', 'carrier' => 'Carrier One', 'tracking_no' => 'SLA-TRACK-1', 'date' => now()->subDays(3)->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        app(ErpSettingService::class)->put('carrier_sla_hours', ['Carrier One' => 24], 'json', $company->id);

        $this->artisan('erp:sales:delivery-sla-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'sla_escalation_level' => 1]);
        $this->assertSame(1, $user->notifications()->whereJsonContains('data->delivery_id', $delivery->id)->count());
        $this->assertSame(1, $user->notifications()->whereJsonContains('data->sla_escalation_level', 1)->count());

        $this->artisan('erp:sales:delivery-sla-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertSame(1, $user->notifications()->whereJsonContains('data->delivery_id', $delivery->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.sla_escalated']);
    }
}
