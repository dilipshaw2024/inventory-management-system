<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\ApprovalPolicy;
use App\Models\Product;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierPriceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_price_agreements_support_idempotent_write_lifecycle(): void
    {
        $company = Company::create(['name' => 'Supplier Pricing Co', 'code' => 'SUPPLIER-PRICING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Price Vendor', 'is_active' => true]);
        $inactiveSupplier = Supplier::create(['company_id' => $company->id, 'name' => 'Inactive Price Vendor', 'is_active' => false]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Pricing Items', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Priced component', 'sku' => 'PRICE-001', 'tracking_type' => 'none', 'quantity' => 0,
            'status' => 1, 'purchase_price' => 10,
        ]);

        Sanctum::actingAs($user, ['purchasing:read', 'purchasing:write']);
        $payload = [
            'supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 10,
            'unit_price' => 8.75, 'currency_code' => 'usd', 'supplier_sku' => 'PV-PRICE-001',
            'lead_time_days' => 4, 'external_reference' => 'vendor-price-001',
        ];
        $created = $this->postJson('/api/integration/supplier-prices', $payload);
        $created->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.external_reference', 'vendor-price-001');
        $priceId = $created->json('data.id');

        $this->postJson('/api/integration/supplier-prices', $payload)
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $priceId);
        $this->patchJson('/api/integration/supplier-prices/'.$priceId, ['unit_price' => 8.5, 'lead_time_days' => 3])
            ->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.unit_price', '8.500000')->assertJsonPath('data.lead_time_days', 3);
        $this->getJson('/api/integration/supplier-prices?supplier_id='.$supplier->id.'&product_id='.$product->id.'&quantity=10')
            ->assertOk()->assertJsonPath('data.0.id', $priceId)->assertJsonPath('data.0.unit_price', '8.500000');
        $this->postJson('/api/integration/supplier-prices/'.$priceId.'/deactivate')
            ->assertOk()->assertJsonPath('status', 'deactivated')->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('supplier_product_prices', ['id' => $priceId, 'company_id' => $company->id, 'is_active' => 0]);

        $permission = Permission::create(['code' => 'purchasing.manage', 'name' => 'Manage purchasing', 'module' => 'purchasing']);
        $role = Role::create(['code' => 'supplier-pricing-manager', 'name' => 'Supplier pricing manager', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $this->actingAs($user)->get('/procurement/supplier-prices')
            ->assertOk()->assertViewHas('suppliers', fn ($rows): bool => $rows->contains('id', $supplier->id) && !$rows->contains('id', $inactiveSupplier->id));
        $this->actingAs($user)->post('/procurement/supplier-prices', [
            'supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 20,
            'unit_price' => 8.25, 'currency_code' => 'eur',
        ])->assertRedirect();
        $this->assertDatabaseHas('supplier_product_prices', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 20, 'currency_code' => 'EUR']);
    }

    public function test_supplier_price_csv_import_supports_dry_run_and_external_reference_versioning(): void
    {
        $company = Company::create(['name' => 'Supplier Import Co', 'code' => 'SUPPLIER-IMPORT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Import Vendor', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Import Items', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Imported component', 'sku' => 'IMPORT-PRICE-001', 'status' => 1]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $csv = "external_reference,supplier_id,product_id,minimum_quantity,unit_price,currency_code,lead_time_days\nprice-import-001,{$supplier->id},{$product->id},5,7.50,usd,6\n";
        $file = fn () => UploadedFile::fake()->createWithContent('supplier-prices.csv', $csv);
        $this->post('/api/integration/supplier-prices/import', ['file' => $file(), 'dry_run' => true])
            ->assertOk()->assertJsonPath('status', 'dry_run')->assertJsonPath('rows', 1)->assertJsonPath('imported', 0);
        $this->post('/api/integration/supplier-prices/import', ['file' => $file()])
            ->assertCreated()->assertJsonPath('status', 'imported')->assertJsonPath('imported', 1);
        $this->assertDatabaseHas('supplier_product_prices', ['company_id' => $company->id, 'external_reference' => 'price-import-001', 'currency_code' => 'USD', 'unit_price' => 7.5]);
        $updatedCsv = str_replace('7.50', '7.25', $csv);
        $this->post('/api/integration/supplier-prices/import', ['file' => UploadedFile::fake()->createWithContent('supplier-prices.csv', $updatedCsv)])
            ->assertCreated()->assertJsonPath('imported', 1);
        $this->assertSame(1, \App\Models\SupplierProductPrice::where('company_id', $company->id)->where('external_reference', 'price-import-001')->count());
        $this->assertDatabaseHas('supplier_product_prices', ['company_id' => $company->id, 'external_reference' => 'price-import-001', 'unit_price' => 7.25]);
    }

    public function test_supplier_price_approval_policy_hides_pending_prices_until_independent_approval(): void
    {
        $company = Company::create(['name' => 'Supplier Approval Co', 'code' => 'SUPPLIER-APPROVAL']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Approval Vendor', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Approval Items', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Approval component', 'sku' => 'APPROVAL-PRICE-001', 'status' => 1]);
        ApprovalPolicy::create(['company_id' => $company->id, 'document_type' => \App\Models\SupplierProductPrice::class, 'approval_step' => 1, 'required_permission' => '', 'is_active' => true]);
        Sanctum::actingAs($maker, ['purchasing:read', 'purchasing:write']);
        $created = $this->postJson('/api/integration/supplier-prices', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 1, 'unit_price' => 6, 'currency_code' => 'USD', 'external_reference' => 'approval-price-001']);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.approval_status', 'pending');
        $priceId = $created->json('data.id');
        $this->getJson('/api/integration/supplier-prices?supplier_id='.$supplier->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/integration/supplier-prices/'.$priceId.'/approve')->assertStatus(422)->assertJsonPath('message', 'Maker-checker control: the creator cannot approve this transaction.');
        Sanctum::actingAs($checker, ['purchasing:read', 'purchasing:write']);
        $this->postJson('/api/integration/supplier-prices/'.$priceId.'/approve')->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('data.approval_status', 'approved');
        $this->getJson('/api/integration/supplier-prices?supplier_id='.$supplier->id)->assertOk()->assertJsonPath('data.0.id', $priceId);
        $second = $this->postJson('/api/integration/supplier-prices', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 2, 'unit_price' => 5, 'currency_code' => 'USD', 'external_reference' => 'approval-price-002'])->assertCreated();
        $this->getJson('/api/integration/supplier-prices/'.$priceId.'/compare/'.$second->json('data.id'))
            ->assertOk()->assertJsonPath('data.changes.minimum_quantity.from', '1.000000')->assertJsonPath('data.changes.minimum_quantity.to', '2.000000')->assertJsonPath('data.changes.unit_price.from', '6.000000')->assertJsonPath('data.changes.unit_price.to', '5.000000');
        $permission = Permission::create(['code' => 'purchasing.manage', 'name' => 'Manage purchasing', 'module' => 'purchasing']);
        $role = Role::create(['code' => 'supplier-price-approval-browser', 'name' => 'Supplier price approval browser', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $maker->roles()->attach($role);
        $checker->roles()->attach($role);
        Sanctum::actingAs($maker, ['purchasing:write']);
        $third = $this->postJson('/api/integration/supplier-prices', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'minimum_quantity' => 3, 'unit_price' => 4, 'currency_code' => 'USD', 'external_reference' => 'approval-price-003'])->assertCreated();
        $thirdId = $third->json('data.id');
        $this->actingAs($maker)->post('/procurement/supplier-prices/'.$thirdId.'/approve')->assertRedirect();
        $this->assertDatabaseHas('supplier_product_prices', ['id' => $thirdId, 'approval_status' => 'pending']);
        $this->actingAs($checker)->post('/procurement/supplier-prices/'.$thirdId.'/approve')->assertRedirect();
        $this->assertDatabaseHas('supplier_product_prices', ['id' => $thirdId, 'approval_status' => 'approved']);
    }
}
