<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReplenishmentPolicy;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_suggestions_pair_surplus_and_shortage_locations(): void
    {
        $company = Company::create(['name' => 'Transfer Planning Co', 'code' => 'TRANSFER-PLAN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Transfer Branch', 'code' => 'TRANSFER-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Transfer Warehouse', 'code' => 'TRANSFER-WAREHOUSE']);
        $source = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Source Bin', 'code' => 'SOURCE-BIN', 'type' => 'bin', 'is_active' => true]);
        $destination = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Destination Bin', 'code' => 'DESTINATION-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Transfer Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Transfer Each', 'status' => 1]);
        $category = Category::create(['name' => 'Transfer Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'name' => 'Transfer item',
            'status' => 1,
            'is_stock_item' => true,
        ]);

        InventoryReplenishmentPolicy::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $source->id,
            'reorder_point' => 2,
            'min_stock' => 2,
            'max_stock' => 10,
            'is_active' => true,
        ]);
        InventoryReplenishmentPolicy::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $destination->id,
            'reorder_point' => 8,
            'min_stock' => 8,
            'max_stock' => 10,
            'is_active' => true,
        ]);
        InventoryMovement::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $source->id,
            'movement_type' => 'receipt',
            'quantity' => 15,
            'unit_cost' => 4,
            'posted_at' => now(),
        ]);

        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
        $response = $this->getJson('/api/inventory/replenishment/transfer-suggestions?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('meta.approval_required', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.source_location_id', $source->id)
            ->assertJsonPath('data.0.destination_location_id', $destination->id)
            ->assertJsonPath('data.0.quantity', 10)
            ->assertJsonPath('data.0.reason', 'replenishment_shortage');

        $this->getJson('/api/inventory/replenishment/transfer-suggestions?product_id='.$product->id.'&destination_location_id='.$destination->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_location_id', $source->id)
            ->assertJsonPath('data.0.destination_location_id', $destination->id);

        $this->assertDatabaseCount('inventory_movements', 1);

        $created = $this->postJson('/api/inventory/replenishment/transfer-suggestions/create-transfer', [
            'external_reference' => 'REPLENISHMENT-TRANSFER-1',
            'product_id' => $product->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'quantity' => 10,
        ]);

        $created->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->assertJsonPath('approval_required', true)
            ->assertJsonPath('data.lines.0.quantity', '10.000000');
        $replayed = $this->postJson('/api/inventory/replenishment/transfer-suggestions/create-transfer', [
            'external_reference' => 'REPLENISHMENT-TRANSFER-1',
            'product_id' => $product->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'quantity' => 99,
        ]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseHas('inventory_transfers', ['external_reference' => 'REPLENISHMENT-TRANSFER-1', 'status' => 'pending']);
        $this->assertSame(1, InventoryTransfer::where('company_id', $company->id)->count());
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->getJson('/api/integration/warehouse/transfers/'.$created->json('data.id').'/reconciliation')
            ->assertOk()->assertJsonPath('summary.planned_quantity', 10)->assertJsonPath('summary.variance_line_count', 0)
            ->assertJsonPath('data.0.reconciliation_status', 'not_posted')->assertJsonPath('read_only', true);

        $warehouseTransfer = $this->postJson('/api/integration/warehouse/transfers', [
            'external_reference' => 'WAREHOUSE-TRANSFER-1',
            'date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                'quantity' => 3,
            ]],
        ]);
        $warehouseTransfer->assertCreated()->assertJsonPath('status', 'pending_approval');
        $warehouseTransferReplay = $this->postJson('/api/integration/warehouse/transfers', [
            'external_reference' => 'WAREHOUSE-TRANSFER-1',
            'date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                'quantity' => 99,
            ]],
        ]);
        $warehouseTransferReplay->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $warehouseTransfer->json('data.id'));

        $putaway = $this->postJson('/api/integration/warehouse/putaway/tasks', [
            'external_reference' => 'PUTAWAY-1',
            'product_id' => $product->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'quantity' => 1,
        ]);
        $putaway->assertCreated()->assertJsonPath('status', 'pending_approval');
        $putawayReplay = $this->postJson('/api/integration/warehouse/putaway/tasks', [
            'external_reference' => 'PUTAWAY-1',
            'product_id' => $product->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'quantity' => 99,
        ]);
        $putawayReplay->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $putaway->json('data.id'));

        $scheduledProduct = Product::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'name' => 'Scheduled transfer item',
            'status' => 1,
            'is_stock_item' => true,
        ]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $scheduledProduct->id, 'location_id' => $source->id, 'reorder_point' => 2, 'min_stock' => 2, 'max_stock' => 10, 'is_active' => true]);
        InventoryReplenishmentPolicy::create(['company_id' => $company->id, 'product_id' => $scheduledProduct->id, 'location_id' => $destination->id, 'reorder_point' => 8, 'min_stock' => 8, 'max_stock' => 10, 'is_active' => true]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $scheduledProduct->id, 'location_id' => $source->id, 'movement_type' => 'receipt', 'quantity' => 15, 'unit_cost' => 4, 'posted_at' => now()]);

        Artisan::call('erp:planning:generate-transfer-orders', ['--company' => $company->id]);
        Artisan::call('erp:planning:generate-transfer-orders', ['--company' => $company->id]);
        $this->assertSame(4, InventoryTransfer::where('company_id', $company->id)->count());
        $this->assertDatabaseHas('inventory_transfers', ['company_id' => $company->id, 'external_reference' => 'replenishment-transfer-'.now()->toDateString().'-'.$scheduledProduct->id.'-'.$source->id.'-'.$destination->id, 'status' => 'pending']);
        $this->assertDatabaseCount('inventory_movements', 2);
    }
}
