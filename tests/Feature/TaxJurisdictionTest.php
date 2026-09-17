<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Role;
use App\Services\TaxRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxJurisdictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_party_jurisdiction_selects_same_code_effective_tax_rate_with_legacy_fallback(): void
    {
        $company = Company::create(['name' => 'Tax Jurisdiction Co', 'code' => 'TAX-JURISDICTION']);
        $default = TaxRate::create(['company_id' => $company->id, 'name' => 'VAT domestic', 'code' => 'VAT', 'rate' => 5, 'calculation' => 'exclusive', 'jurisdiction' => 'DOMESTIC', 'effective_from' => '2026-01-01', 'is_active' => true]);
        TaxRate::create(['company_id' => $company->id, 'name' => 'VAT export', 'code' => 'VAT', 'rate' => 12, 'calculation' => 'exclusive', 'jurisdiction' => 'EXPORT', 'effective_from' => '2026-01-01', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Tax supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Tax each', 'code' => 'TAX-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Tax category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Jurisdiction item', 'tax_rate_id' => $default->id, 'tax_rate' => 3, 'status' => 1]);

        $resolver = app(TaxRateResolver::class);

        $this->assertSame(12.0, $resolver->rateFor($product, '2026-09-16', 'EXPORT'));
        $this->assertSame(5.0, $resolver->rateFor($product, '2026-09-16'));
        $this->assertSame(5.0, $resolver->rateFor($product, '2026-09-16', 'UNKNOWN'));

        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);
        $payload = ['name' => 'API VAT', 'code' => 'API-VAT', 'rate' => 5, 'calculation' => 'exclusive', 'effective_from' => '2026-01-01'];
        $this->postJson('/api/accounting/tax-rates', $payload + ['jurisdiction' => 'DOMESTIC'])->assertCreated();
        $this->postJson('/api/accounting/tax-rates', $payload + ['jurisdiction' => 'EXPORT', 'rate' => 12])->assertCreated();

        foreach (['DOMESTIC', 'EXPORT'] as $index => $jurisdiction) {
            $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'TAX-JURIS-'.$index, 'date' => '2026-09-16', 'status' => 1, 'tax_jurisdiction' => $jurisdiction, 'subtotal_amount' => 100, 'tax_amount' => 5, 'total_amount' => 105]);
            $invoice->invoice_details()->create(['date' => '2026-09-16', 'product_id' => $product->id, 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'tax_rate' => 5, 'tax_amount' => 5, 'status' => 1]);
        }
        $report = $this->getJson('/api/accounting/tax-report?from=2026-09-01&to=2026-09-30');
        $report->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.jurisdiction', 'DOMESTIC')->assertJsonPath('data.1.jurisdiction', 'EXPORT');
        $filteredReport = $this->getJson('/api/accounting/tax-report?from=2026-09-01&to=2026-09-30&jurisdiction=EXPORT');
        $filteredReport->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.jurisdiction', 'EXPORT')->assertJsonPath('meta.jurisdiction', 'EXPORT');

        $permission = Permission::create(['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting']);
        $role = Role::create(['code' => 'tax-reporting', 'name' => 'Tax reporting', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $browser = $this->actingAs($user)->get('/erp/accounting/tax-report?from=2026-09-01&to=2026-09-30');
        $browser->assertOk()->assertViewHas('rows', function ($rows): bool {
            return $rows->count() === 2 && $rows->first()['jurisdiction'] === 'DOMESTIC' && $rows->last()['jurisdiction'] === 'EXPORT';
        });
        $filteredBrowser = $this->actingAs($user)->get('/erp/accounting/tax-report?from=2026-09-01&to=2026-09-30&jurisdiction=EXPORT');
        $filteredBrowser->assertOk()->assertViewHas('jurisdiction', 'EXPORT')->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1 && $rows->first()['jurisdiction'] === 'EXPORT');
    }
}
