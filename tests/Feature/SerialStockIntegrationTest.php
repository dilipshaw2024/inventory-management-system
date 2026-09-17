<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialStockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_api_exposes_company_scoped_serial_stock(): void
    {
        $company = Company::create(['name' => 'Serial Tenant', 'code' => 'SERIAL-TENANT']);
        $otherCompany = Company::create(['name' => 'Other Tenant', 'code' => 'SERIAL-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Serial Supplier', 'is_active' => true]);
        $otherSupplier = Supplier::create(['company_id' => $otherCompany->id, 'name' => 'Other Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Serial Devices', 'status' => 1]);
        $otherCategory = Category::create(['name' => 'Other Devices', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Serialized device', 'sku' => 'SERIAL-001', 'tracking_type' => 'serial', 'quantity' => 1, 'status' => 1]);
        $otherProduct = Product::create(['company_id' => $otherCompany->id, 'supplier_id' => $otherSupplier->id, 'unit_id' => $unit->id, 'category_id' => $otherCategory->id, 'name' => 'Other device', 'sku' => 'SERIAL-002', 'tracking_type' => 'serial', 'quantity' => 1, 'status' => 1]);
        $serial = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'SN-001', 'status' => 'available']);
        InventorySerial::create(['product_id' => $otherProduct->id, 'serial_no' => 'SN-OTHER', 'status' => 'available']);
        $token = $user->createToken('inventory-read-test', ['inventory:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/inventory/serials?status=available');

        $response->assertOk()->assertJsonPath('data.0.id', $serial->id)->assertJsonPath('data.0.product_id', $product->id);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_inventory_api_reports_ledger_derived_batch_stock(): void
    {
        $company = Company::create(['name' => 'Batch Tenant', 'code' => 'BATCH-TENANT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Batch Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Box', 'status' => 1]);
        $category = Category::create(['name' => 'Batch Goods', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Batched goods', 'sku' => 'BATCH-001', 'tracking_type' => 'batch', 'quantity' => 4, 'status' => 1, 'purchase_price' => 7.50]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'LOT-001']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 7.50, 'batch_id' => $batch->id, 'reason' => 'Test receipt']);
        $token = $user->createToken('batch-read-test', ['inventory:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/inventory/batches/stock?product_id='.$product->id);

        $response->assertOk()->assertJsonPath('data.0.id', $batch->id)->assertJsonPath('data.0.stock_quantity', 4);
        $this->assertSame(1, $response->json('meta.total'));
    }
}
