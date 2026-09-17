<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\UomConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_uom_conversion_is_reusable_and_used_as_product_fallback(): void
    {
        $company = Company::create(['name' => 'UOM Conversion Co', 'code' => 'UOM-CONV']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $kg = Unit::create(['company_id' => $company->id, 'name' => 'Kilogram', 'code' => 'KG', 'dimension' => 'weight', 'status' => 1, 'is_base' => true]);
        $box = Unit::create(['company_id' => $company->id, 'name' => 'Box', 'code' => 'BOX', 'dimension' => 'weight', 'status' => 1]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'UOM Supplier', 'is_active' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'UOM Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $kg->id, 'category_id' => $category->id, 'name' => 'Bulk item', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:write', 'inventory:read']);

        $response = $this->postJson('/api/inventory/uom-conversions', ['from_unit_id' => $box->id, 'to_unit_id' => $kg->id, 'factor' => 12, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'external_reference' => 'BOX-KG-1']);

        $response->assertCreated()->assertJsonPath('data.factor', '12.000000000000');
        $conversion = UnitConversion::firstOrFail();
        $this->assertSame($company->id, $conversion->company_id);
        $this->assertSame(24.0, app(UomConversionService::class)->toStock($product, 2, $box->id));
        $this->postJson('/api/inventory/uom-conversions', ['from_unit_id' => $box->id, 'to_unit_id' => $kg->id, 'factor' => 15, 'effective_from' => '2027-01-01'])->assertCreated();
        $this->assertSame(30.0, app(UomConversionService::class)->toStock($product, 2, $box->id, null, '2027-01-15'));
        $case = Unit::create(['company_id' => $company->id, 'name' => 'Case', 'code' => 'CASE', 'dimension' => 'weight', 'status' => 1]);
        $this->postJson('/api/inventory/uom-conversions', ['from_unit_id' => $box->id, 'to_unit_id' => $case->id, 'factor' => 10])->assertCreated();
        $this->postJson('/api/inventory/uom-conversions', ['from_unit_id' => $case->id, 'to_unit_id' => $kg->id, 'factor' => 1.2])->assertCreated();
        $this->postJson('/api/inventory/uom-conversions/'.$conversion->id.'/deactivate')->assertOk();
        $this->assertSame(24.0, app(UomConversionService::class)->toStock($product, 2, $box->id));
        $this->getJson('/api/inventory/uom-conversions?from_unit_id='.$box->id)->assertOk()->assertJsonPath('data.data.0.id', $conversion->id);
    }
}
