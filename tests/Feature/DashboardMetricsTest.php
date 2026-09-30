<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_api_returns_cached_trends_and_exception_drilldowns(): void
    {
        $company = Company::create(['name' => 'Dashboard Co', 'code' => 'DASHBOARD-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Dashboard Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Dashboard Each', 'code' => 'EA-DASH', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Dashboard Category', 'status' => 1]);
        Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Dashboard low item', 'sku' => 'DASH-LOW', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true, 'quantity' => 0, 'reorder_level' => 5]);
        Sanctum::actingAs($user, ['inventory:read']);

        $response = $this->getJson('/api/inventory/dashboard?months=3');

        $response->assertOk()->assertJsonPath('meta.months', 3)->assertJsonPath('meta.cached', true)->assertJsonCount(3, 'data.sales_trend')->assertJsonStructure(['data' => ['exception_drilldowns' => ['low_stock', 'excess_stock']]]);
        $this->assertSame('DASH-LOW', $response->json('data.exception_drilldowns.low_stock.0.sku'));
    }

    public function test_assigned_role_without_finance_or_service_permission_receives_scoped_kpis(): void
    {
        $company = Company::create(['name' => 'Scoped Dashboard Co', 'code' => 'SCOPED-DASHBOARD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::create(['name' => 'Inventory Operator', 'code' => 'inventory-operator', 'is_active' => true]);
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user, ['inventory:read']);

        $response = $this->getJson('/api/inventory/dashboard?months=3');

        $response->assertOk()->assertJsonPath('meta.visibility.finance', false)->assertJsonPath('meta.visibility.service', false)->assertJsonPath('data.month_sales', null)->assertJsonPath('data.receivables', null)->assertJsonPath('data.sales_trend', [])->assertJsonPath('data.open_service_requests', null);
        $response->assertJsonPath('meta.visibility.inventory', false)->assertJsonPath('data.total_products', null)->assertJsonPath('data.low_stock', null)->assertJsonPath('data.exception_drilldowns.low_stock', [])->assertJsonPath('data.pending_approvals', null);
    }
    public function test_company_can_select_dashboard_widgets_without_removing_legacy_fields(): void
    {
        $company = Company::create(['name' => 'Widget Dashboard Co', 'code' => 'WIDGET-DASHBOARD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write', 'inventory:read']);

        $this->patchJson('/api/accounting/settings', [
            'dashboard_widgets' => ['month_sales', 'low_stock'],
        ])->assertOk()->assertJsonPath('data.dashboard_widgets.0', 'month_sales');

        $response = $this->getJson('/api/inventory/dashboard?months=3');
        $response->assertOk()
            ->assertJsonPath('data.enabled_widgets.0', 'month_sales')
            ->assertJsonPath('data.enabled_widgets.1', 'low_stock')
            ->assertJsonPath('data.widgets.month_sales', 0)
            ->assertJsonPath('data.widgets.low_stock', 0)
            ->assertJsonPath('data.open_service_requests', 0);
    }

}
