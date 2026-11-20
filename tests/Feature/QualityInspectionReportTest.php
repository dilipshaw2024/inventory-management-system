<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\QualityInspection;
use App\Models\QualityInspectionPlan;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityInspectionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_report_is_permission_protected_filterable_and_exportable(): void
    {
        $company = Company::create(['name' => 'Quality Report Co', 'code' => 'QUALITY-REPORT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permissions = collect(['reports.view', 'reports.export'])->map(fn (string $code): Permission => Permission::create(['code' => $code, 'name' => $code, 'module' => 'reports']));
        $role = Role::create(['code' => 'quality-report-reader', 'name' => 'Quality report reader', 'is_active' => true]);
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id);
        $unit = Unit::create(['name' => 'Quality report each', 'code' => 'QREP-EA', 'status' => 1]);
        $category = Category::create(['name' => 'Quality report category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Quality report item', 'sku' => 'QREP-1', 'quantity' => 0, 'status' => 1]);
        $plan = QualityInspectionPlan::create(['company_id' => $company->id, 'product_id' => $product->id, 'code' => 'QREP-PLAN', 'name' => 'Quality report plan', 'inspection_type' => 'final', 'sampling_percent' => 50, 'is_active' => true]);
        QualityInspection::create(['company_id' => $company->id, 'plan_id' => $plan->id, 'product_id' => $product->id, 'inspection_no' => 'QREP-INS-1', 'quantity' => 8, 'sample_quantity' => 4, 'status' => 'passed', 'disposition' => 'release', 'created_at' => '2026-11-01 10:00:00', 'completed_at' => '2026-11-01 11:00:00']);

        $this->actingAs($user)->get('/reports/quality-inspections?from=2026-11-01&to=2026-11-30&inspection_type=final')
            ->assertOk()->assertSee('Quality Inspection Report')->assertSee('QREP-INS-1')->assertSee('100.00%');
        $response = $this->actingAs($user)->get('/reports/quality-inspections/export?from=2026-11-01&to=2026-11-30&status=passed');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('QREP-INS-1', $response->streamedContent());
    }
}
