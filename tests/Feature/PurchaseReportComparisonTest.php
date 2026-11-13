<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReportComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_purchase_pdf_can_compare_approved_periods(): void
    {
        $company = Company::create(['name' => 'Purchase Report Co', 'code' => 'PURCHASE-REPORT']);
        $permission = Permission::create(['code' => 'purchasing.manage', 'name' => 'Manage purchasing', 'module' => 'purchasing']);
        $exportPermission = Permission::create(['code' => 'reports.export', 'name' => 'Export reports', 'module' => 'reports']);
        $role = Role::create(['code' => 'purchase-report-viewer', 'name' => 'Purchase report viewer', 'is_active' => true]);
        $role->permissions()->attach([$permission->id, $exportPermission->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        Purchase::create(['company_id' => $company->id, 'supplier_id' => 1, 'category_id' => 1, 'product_id' => 1, 'purchase_no' => 'CUR-001', 'date' => '2026-03-15', 'buying_qty' => 10, 'unit_price' => 15, 'buying_price' => 150, 'status' => 1]);
        Purchase::create(['company_id' => $company->id, 'supplier_id' => 1, 'category_id' => 1, 'product_id' => 1, 'purchase_no' => 'PRIOR-001', 'date' => '2026-02-15', 'buying_qty' => 5, 'unit_price' => 10, 'buying_price' => 50, 'status' => 1]);

        $this->actingAs($user)->get('/daily/purchase/pdf?start_date=2026-03-01&end_date=2026-03-31&compare_start_date=2026-02-01&compare_end_date=2026-02-28')
            ->assertOk()
            ->assertSee('Amount variance')
            ->assertSee('200.00%')
            ->assertSee('150.00');

        $export = $this->actingAs($user)->get('/daily/purchase/export?start_date=2026-03-01&end_date=2026-03-31&format=csv');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $export->streamedContent();
        $this->assertStringContainsString('Purchase no', $content);
        $this->assertStringContainsString('CUR-001', $content);
        $this->assertStringNotContainsString('PRIOR-001', $content);
    }
}
