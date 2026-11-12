<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
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
        $this->postJson('/api/accounting/tax-rates', array_merge($payload, ['jurisdiction' => 'DOMESTIC', 'components' => [
            ['code' => 'CENTRAL', 'name' => 'Central tax', 'rate' => 3, 'component_type' => 'central'],
            ['code' => 'STATE', 'name' => 'State tax', 'rate' => 2, 'component_type' => 'state'],
        ]]))->assertCreated()->assertJsonPath('data.components.0.code', 'CENTRAL');
        $this->postJson('/api/accounting/tax-rates', array_merge($payload, ['jurisdiction' => 'EXPORT', 'rate' => 12, 'components' => [
            ['code' => 'FEDERAL', 'name' => 'Federal tax', 'rate' => 7, 'component_type' => 'central'],
            ['code' => 'LOCAL', 'name' => 'Local tax', 'rate' => 5, 'component_type' => 'local'],
        ]]))->assertCreated();
        $this->postJson('/api/accounting/tax-rates', array_merge($payload, ['jurisdiction' => 'INVALID', 'components' => [
            ['code' => 'ONLY', 'name' => 'Incomplete tax', 'rate' => 4],
        ]]))->assertStatus(422);

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

    public function test_browser_tax_configuration_persists_component_breakdown(): void
    {
        $company = Company::create(['name' => 'Browser Tax Co', 'code' => 'BROWSER-TAX']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting']);
        $role = Role::create(['code' => 'browser-tax-admin', 'name' => 'Browser tax admin', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->post('/erp/finance/tax', [
            'name' => 'GST 18', 'code' => 'GST18', 'rate' => 18, 'calculation' => 'exclusive', 'jurisdiction' => 'DOMESTIC',
            'components_json' => json_encode([
                ['code' => 'CENTRAL', 'name' => 'Central tax', 'rate' => 9, 'component_type' => 'central'],
                ['code' => 'STATE', 'name' => 'State tax', 'rate' => 9, 'component_type' => 'state', 'is_compound' => false],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response->assertRedirect();
        $tax = TaxRate::where('company_id', $company->id)->where('code', 'GST18')->firstOrFail();
        $this->assertCount(2, $tax->components);
        $this->assertDatabaseHas('tax_rate_components', ['tax_rate_id' => $tax->id, 'code' => 'STATE', 'rate' => 9]);

        $this->actingAs($user)->patch('/erp/finance/tax/'.$tax->id, [
            'name' => 'GST 18 revised', 'code' => 'GST18', 'rate' => 18, 'calculation' => 'inclusive', 'jurisdiction' => 'DOMESTIC',
            'components_json' => json_encode([['code' => 'GST', 'name' => 'Unified GST', 'rate' => 18, 'component_type' => 'other']], JSON_THROW_ON_ERROR),
        ])->assertRedirect();
        $this->assertDatabaseHas('tax_rate_components', ['tax_rate_id' => $tax->id, 'code' => 'GST', 'rate' => 18]);
        $this->assertDatabaseMissing('tax_rate_components', ['tax_rate_id' => $tax->id, 'code' => 'STATE']);
    }

    public function test_tax_exemption_expiry_disables_exemption_after_certificate_date(): void
    {
        $customer = Customer::create([
            'name' => 'Expiring exemption customer', 'tax_exempt' => true,
            'tax_exemption_number' => 'CERT-EXPIRING', 'tax_exemption_expires_at' => '2026-11-10',
        ]);

        $this->assertFalse($customer->taxExemptionIsActive(\Carbon\Carbon::parse('2026-11-11')));
        $customer->update(['tax_exemption_expires_at' => '2026-11-11']);
        $this->assertTrue($customer->fresh()->taxExemptionIsActive(\Carbon\Carbon::parse('2026-11-11')));
    }

    public function test_tax_exemption_certificate_feed_is_tenant_scoped_and_filters_expiring_parties(): void
    {
        $company = Company::create(['name' => 'Certificate Feed Co', 'code' => 'CERT-FEED']);
        $otherCompany = Company::create(['name' => 'Other Certificate Co', 'code' => 'CERT-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('certificate-feed', ['accounting:read'])->plainTextToken;
        $today = now()->startOfDay();

        Customer::create(['company_id' => $company->id, 'name' => 'Expiring customer', 'code' => 'C-EXPIRING', 'tax_exempt' => true, 'tax_exemption_number' => 'C-EXPIRING', 'tax_exemption_expires_at' => $today->copy()->addDays(20)]);
        Supplier::create(['company_id' => $company->id, 'name' => 'Expired supplier', 'code' => 'S-EXPIRED', 'tax_exempt' => true, 'tax_exemption_number' => 'S-EXPIRED', 'tax_exemption_expires_at' => $today->copy()->subDay()]);
        Customer::create(['company_id' => $company->id, 'name' => 'Open customer', 'code' => 'C-OPEN', 'tax_exempt' => true, 'tax_exemption_number' => 'C-OPEN']);
        Customer::create(['company_id' => $otherCompany->id, 'name' => 'Other tenant customer', 'code' => 'C-OTHER', 'tax_exempt' => true, 'tax_exemption_expires_at' => $today->copy()->addDays(5)]);

        $expiring = $this->withToken($token)->getJson('/api/accounting/tax-exemption-certificates?status=expiring&days=30');
        $expiring->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'C-EXPIRING')->assertJsonPath('data.0.status', 'expiring');

        $expired = $this->withToken($token)->getJson('/api/accounting/tax-exemption-certificates?status=expired');
        $expired->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'S-EXPIRED')->assertJsonPath('data.0.status', 'expired');

        $all = $this->withToken($token)->getJson('/api/accounting/tax-exemption-certificates');
        $all->assertOk()->assertJsonPath('meta.total', 3)->assertJsonMissing(['code' => 'C-OTHER']);
    }
}
