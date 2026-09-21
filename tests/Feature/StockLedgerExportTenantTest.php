<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
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

    private function product(Company $company, string $name): Product
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => $name.' supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => $name.' unit', 'code' => strtoupper(substr(md5($name), 0, 8)), 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => $name.' category', 'status' => 1]);
        return Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => $name, 'quantity' => 0, 'purchase_price' => 10, 'status' => 1]);
    }
}
