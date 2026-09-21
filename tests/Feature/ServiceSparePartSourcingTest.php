<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ServiceAsset;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceSparePartSourcingTest extends TestCase
{
    use RefreshDatabase;

    public function test_spare_part_vendor_sourcing_metadata_is_created_listed_updated_and_validated(): void
    {
        $company = Company::create(['name' => 'Service Sourcing Co', 'code' => 'SERVICE-SOURCING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Preferred Parts Vendor', 'is_active' => true]);
        $inactiveSupplier = Supplier::create(['company_id' => $company->id, 'name' => 'Inactive Vendor', 'is_active' => false]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Service Parts', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Replacement filter', 'sku' => 'FILTER-SOURCE-001', 'tracking_type' => 'none',
            'quantity' => 1, 'status' => 1, 'is_stock_item' => true, 'purchase_price' => 12,
        ]);
        $asset = ServiceAsset::create([
            'company_id' => $company->id, 'asset_no' => 'ASSET-SOURCE-001', 'name' => 'Production machine', 'status' => 'active',
        ]);

        Sanctum::actingAs($user, ['service:read', 'service:write']);
        $created = $this->postJson('/api/service/assets/'.$asset->id.'/spare-parts', [
            'product_id' => $product->id, 'supplier_id' => $supplier->id, 'supplier_part_no' => 'VENDOR-FILTER-7',
            'lead_time_days' => 7, 'supplier_unit_cost' => 12.5, 'supplier_currency' => 'USD',
            'preferred_supplier' => true, 'quantity_per_service' => 1, 'minimum_stock' => 2, 'maximum_stock' => 10,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.supplier.id', $supplier->id)
            ->assertJsonPath('data.supplier_part_no', 'VENDOR-FILTER-7')
            ->assertJsonPath('data.lead_time_days', 7)
            ->assertJsonPath('data.supplier_currency', 'USD')
            ->assertJsonPath('data.preferred_supplier', true);
        $partId = $created->json('data.id');

        $this->getJson('/api/service/assets/'.$asset->id.'/spare-parts')
            ->assertOk()->assertJsonPath('data.0.supplier.name', 'Preferred Parts Vendor')
            ->assertJsonPath('data.0.supplier_unit_cost', '12.500000');
        $catalog = $this->getJson('/api/service/spare-parts?cursor_mode=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.feed', 'service.spare-parts')->assertJsonPath('meta.has_more', false);
        $this->assertSame($partId, $catalog->json('data.0.id'));

        $replenishment = $this->getJson('/api/service/spare-part-replenishment')->assertOk()->assertJsonPath('meta.read_only', true);
        $this->assertCount(1, $replenishment->json('data'));
        $this->assertSame($product->id, $replenishment->json('data.0.product.id'));
        $this->assertEqualsWithDelta(9.0, (float) $replenishment->json('data.0.suggested_quantity'), 0.000001);
        $this->assertEqualsWithDelta(112.5, (float) $replenishment->json('data.0.estimated_cost'), 0.000001);

        $purchasePayload = ['external_reference' => 'SERVICE-RESTOCK-1', 'date' => '2026-09-20', 'expected_date' => '2026-09-27', 'items' => [['asset_spare_part_id' => $partId, 'quantity' => 9]]];
        $purchaseOrder = $this->postJson('/api/service/spare-part-replenishment/purchase-orders', $purchasePayload)
            ->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.0.status', 'submitted')->assertJsonPath('data.0.supplier.id', $supplier->id);
        $this->assertDatabaseHas('purchase_order_lines', ['purchase_order_id' => $purchaseOrder->json('data.0.id'), 'product_id' => $product->id, 'ordered_qty' => 9]);
        $this->postJson('/api/service/spare-part-replenishment/purchase-orders', $purchasePayload)
            ->assertOk()->assertJsonPath('status', 'existing')->assertJsonPath('data.0.id', $purchaseOrder->json('data.0.id'));

        $this->artisan('erp:service:generate-spare-part-purchase-orders', ['--company' => $company->id])
            ->assertExitCode(0);
        $scheduledReference = 'service-spare-parts-'.now()->toDateString().'-'.$company->id;
        $this->assertDatabaseHas('purchase_orders', ['company_id' => $company->id, 'external_reference' => $scheduledReference, 'status' => 'submitted']);
        $scheduledCount = DB::table('purchase_orders')->where('company_id', $company->id)->where('external_reference', $scheduledReference)->count();
        $this->artisan('erp:service:generate-spare-part-purchase-orders', ['--company' => $company->id])
            ->assertExitCode(0);
        $this->assertSame($scheduledCount, DB::table('purchase_orders')->where('company_id', $company->id)->where('external_reference', $scheduledReference)->count());

        $this->patchJson('/api/service/assets/'.$asset->id.'/spare-parts/'.$partId, [
            'lead_time_days' => 5, 'supplier_unit_cost' => 13.25,
        ])->assertOk()->assertJsonPath('data.lead_time_days', 5)->assertJsonPath('data.supplier_unit_cost', '13.250000');

        $this->postJson('/api/service/assets/'.$asset->id.'/spare-parts', [
            'product_id' => $product->id, 'supplier_id' => $inactiveSupplier->id, 'quantity_per_service' => 1,
        ])->assertStatus(422)->assertJsonPath('message', 'The selected supplier is not active or authorized.');
    }
}
