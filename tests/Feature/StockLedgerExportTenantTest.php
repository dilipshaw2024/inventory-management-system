<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryCostLayer;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLedgerExportTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_ledger_export_is_company_scoped(): void
    {
        $companyA = Company::create(['name' => 'Ledger Export A', 'code' => 'LEDGER-EXPORT-A']);
        $companyB = Company::create(['name' => 'Ledger Export B', 'code' => 'LEDGER-EXPORT-B']);
        $user = User::factory()->create(['company_id' => $companyA->id]);
        $permissions = collect(['reports.view', 'reports.export'])->map(fn (string $code): Permission => Permission::create(['code' => $code, 'name' => $code, 'module' => 'reports']));
        $role = Role::create(['code' => 'ledger-exporter', 'name' => 'Ledger exporter', 'is_active' => true]);
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id);

        $productA = $this->product($companyA, 'Visible ledger item');
        $productB = $this->product($companyB, 'Hidden ledger item');
        InventoryMovement::create(['company_id' => $companyA->id, 'product_id' => $productA->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 10, 'reference_no' => 'A-RECEIPT', 'posted_at' => now()]);
        InventoryMovement::create(['company_id' => $companyB->id, 'product_id' => $productB->id, 'movement_type' => 'receipt', 'quantity' => 9, 'unit_cost' => 20, 'reference_no' => 'B-RECEIPT', 'posted_at' => now()]);

        $this->actingAs($user);
        $response = $this->get('/stock/movements/export');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Visible ledger item', $csv);
        $this->assertStringNotContainsString('Hidden ledger item', $csv);
        $this->get('/stock/movements/export?product_id='.$productB->id)
            ->assertRedirect()->assertSessionHasErrors('product_id');
    }

    public function test_valuation_export_reuses_filters_and_requires_export_permission(): void
    {
        $company = Company::create(['name' => 'Valuation Export Co', 'code' => 'VALUATION-EXPORT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $viewPermission = Permission::create(['code' => 'reports.view', 'name' => 'reports.view', 'module' => 'reports']);
        $role = Role::create(['code' => 'valuation-viewer', 'name' => 'Valuation viewer', 'is_active' => true]);
        $role->permissions()->attach($viewPermission->id);
        $user->roles()->attach($role->id);
        $product = $this->product($company, 'Valuation export item');
        InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 6, 'remaining_quantity' => 6, 'unit_cost' => 11.5, 'received_at' => now()->subDay()]);

        $this->actingAs($user);
        $this->get('/stock/valuation/export?format=csv')->assertForbidden();

        $exportPermission = Permission::create(['code' => 'reports.export', 'name' => 'reports.export', 'module' => 'reports']);
        $role->permissions()->attach($exportPermission->id);
        $response = $this->get('/stock/valuation/export?format=csv&product_id='.$product->id);
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Product,SKU,Category,"Quantity balance","Cost-layer value"', $csv);
        $this->assertStringContainsString('Valuation export item', $csv);
        $this->assertStringContainsString('69.000000', $csv);

        $this->get('/stock/valuation/pdf?format=pdf&product_id='.$product->id)
            ->assertOk()->assertSee('Inventory Valuation')->assertSee('Valuation export item');
    }

    public function test_browser_valuation_shows_accounting_period_reconciliation(): void
    {
        $company = Company::create(['name' => 'Browser Period Co', 'code' => 'BROWSER-PERIOD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'reports.view', 'name' => 'reports.view', 'module' => 'reports']);
        $role = Role::create(['code' => 'browser-period-viewer', 'name' => 'Browser period viewer', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $product = $this->product($company, 'Browser period item');
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY BROWSER', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open']);
        $movement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 10, 'posted_at' => '2026-09-05 10:00:00']);
        InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 5, 'remaining_quantity' => 5, 'unit_cost' => 10, 'source_type' => $movement->getMorphClass(), 'source_id' => $movement->id, 'received_at' => '2026-09-05 10:00:00']);

        $this->actingAs($user);
        $this->get('/stock/valuation?fiscal_period_id='.$period->id)
            ->assertOk()
            ->assertSee('Accounting-period reconciliation')
            ->assertSee('Opening value')
            ->assertSee('50.00')
            ->assertSee('Unexplained variance');
    }

    private function product(Company $company, string $name): Product
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => $name.' supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => $name.' unit', 'code' => strtoupper(substr(md5($name), 0, 8)), 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => $name.' category', 'status' => 1]);
        return Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => $name, 'quantity' => 0, 'purchase_price' => 10, 'status' => 1]);
    }
}
