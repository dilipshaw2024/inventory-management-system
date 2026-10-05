<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\ServiceAsset;
use App\Models\ServiceAssetSerialHandoff;
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

    public function test_inventory_api_exposes_batch_traceability_with_direction_filter(): void
    {
        $company = Company::create(['name' => 'Trace Tenant', 'code' => 'TRACE-TENANT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Trace Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Trace Goods', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Traceable goods', 'sku' => 'TRACE-001', 'tracking_type' => 'batch', 'quantity' => 2, 'status' => 1]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'TRACE-LOT-001']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Trace Customer', 'status' => 1]);
        $return = \App\Models\InventoryReturn::create(['company_id' => $company->id, 'return_no' => 'RET-BATCH-TRACE-001', 'return_type' => 'sales', 'customer_id' => $customer->id, 'date' => '2026-09-25', 'reason_code' => 'traceability', 'status' => 'approved']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 3, 'batch_id' => $batch->id, 'reason' => 'Trace receipt']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 3, 'batch_id' => $batch->id, 'reference_type' => $return->getMorphClass(), 'reference_id' => $return->id, 'reason' => 'Trace issue']);
        $token = $user->createToken('trace-read-test', ['inventory:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/inventory/batches/'.$batch->id.'/traceability?direction=outbound');

        $response->assertOk()->assertJsonPath('data.0.batch_id', $batch->id)->assertJsonPath('data.0.movement_type', 'issue')->assertJsonPath('data.0.source_context.party_type', 'customer')->assertJsonPath('data.0.source_context.party_name', 'Trace Customer');
        $this->assertSame(1, $response->json('total'));
    }

    public function test_inventory_api_exposes_serial_chain_of_custody(): void
    {
        $company = Company::create(['name' => 'Serial Trace Tenant', 'code' => 'SERIAL-TRACE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Serial Trace Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Serial Trace Goods', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Serial traceable item', 'sku' => 'SERIAL-TRACE-001', 'tracking_type' => 'serial', 'quantity' => 1, 'status' => 1]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'SERIAL-TRACE-LOT']);
        $serial = InventorySerial::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'serial_no' => 'SN-TRACE-001', 'status' => 'issued']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Serial Trace Customer', 'status' => 1]);
        $return = \App\Models\InventoryReturn::create(['company_id' => $company->id, 'return_no' => 'RET-SERIAL-TRACE-001', 'return_type' => 'sales', 'customer_id' => $customer->id, 'date' => '2026-09-25', 'reason_code' => 'traceability', 'status' => 'approved']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 1, 'unit_cost' => 9, 'batch_id' => $batch->id, 'serial_id' => $serial->id, 'reason' => 'Serial trace receipt']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 1, 'unit_cost' => 9, 'batch_id' => $batch->id, 'serial_id' => $serial->id, 'reference_type' => $return->getMorphClass(), 'reference_id' => $return->id, 'reason' => 'Serial trace issue']);
        $token = $user->createToken('serial-trace-read-test', ['inventory:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/inventory/serials/'.$serial->id.'/traceability?direction=outbound');

        $response->assertOk()->assertJsonPath('data.0.serial_id', $serial->id)->assertJsonPath('data.0.movement_type', 'issue');
        $this->assertSame(1, $response->json('total'));

        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'ASSET-TRACE-001', 'name' => 'Installed trace device', 'product_id' => $product->id, 'customer_id' => null, 'inventory_serial_id' => $serial->id, 'serial_no' => $serial->serial_no, 'status' => 'active']);
        // Keep the handoff after the issue movement regardless of suite wall-clock time.
        ServiceAssetSerialHandoff::create(['company_id' => $company->id, 'service_asset_id' => $asset->id, 'inventory_serial_id' => $serial->id, 'action' => 'installed', 'effective_at' => now()->addMinute(), 'location' => 'Customer site']);

        $custody = $this->withToken($token)->getJson('/api/inventory/serials/'.$serial->id.'/chain-of-custody?direction=outbound');
        $custody->assertOk()->assertJsonPath('data.0.event_type', 'inventory_movement')->assertJsonPath('data.0.source_context.party_type', 'customer')->assertJsonPath('data.0.source_context.party_name', 'Serial Trace Customer')->assertJsonPath('data.1.event_type', 'service_handoff')->assertJsonPath('data.1.action', 'installed');
        $this->assertSame(2, $custody->json('meta.total'));
    }
    public function test_unified_traceability_feed_filters_company_scoped_movement_history(): void
    {
        $company = Company::create(['name' => 'Unified Trace Tenant', 'code' => 'UNIFIED-TRACE']);
        $otherCompany = Company::create(['name' => 'Unified Other Tenant', 'code' => 'UNIFIED-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Unified Goods', 'status' => 1]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Unified Supplier', 'is_active' => true]);
        $otherSupplier = Supplier::create(['company_id' => $otherCompany->id, 'name' => 'Other Unified Supplier', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Unified trace item', 'sku' => 'UNIFIED-001', 'tracking_type' => 'batch', 'quantity' => 4, 'status' => 1]);
        $otherProduct = Product::create(['company_id' => $otherCompany->id, 'supplier_id' => $otherSupplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Other trace item', 'sku' => 'UNIFIED-002', 'tracking_type' => 'batch', 'quantity' => 9, 'status' => 1]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'UNIFIED-LOT']);
        $otherBatch = InventoryBatch::create(['product_id' => $otherProduct->id, 'batch_no' => 'OTHER-LOT']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 5, 'batch_id' => $batch->id, 'reason' => 'Unified inbound']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 1, 'unit_cost' => 5, 'batch_id' => $batch->id, 'reason' => 'Unified outbound']);
        InventoryMovement::create(['company_id' => $otherCompany->id, 'product_id' => $otherProduct->id, 'movement_type' => 'receipt', 'quantity' => 9, 'unit_cost' => 7, 'batch_id' => $otherBatch->id, 'reason' => 'Other tenant']);
        $token = $user->createToken('unified-trace-read-test', ['inventory:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/inventory/traceability?product_id='.$product->id.'&direction=outbound&per_page=1');

        $response->assertOk()->assertJsonPath('data.0.product_id', $product->id)->assertJsonPath('data.0.batch_id', $batch->id)->assertJsonPath('data.0.movement_type', 'issue');
        $this->assertSame(1, $response->json('total'));
        $this->assertNotSame($otherProduct->id, $response->json('data.0.product_id'));
    }

}
