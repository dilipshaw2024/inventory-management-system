<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryIntercompanyReceipt;
use App\Models\InventoryIntercompanyTransfer;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntercompanyTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliated_companies_can_dispatch_and_receive_shared_inventory_with_separate_ledgers(): void
    {
        $parent = Company::create(['name' => 'Intercompany Parent', 'code' => 'IC-PARENT']);
        $subsidiary = Company::create(['name' => 'Intercompany Subsidiary', 'code' => 'IC-SUB', 'parent_company_id' => $parent->id]);
        $sourceCreator = User::factory()->create(['company_id' => $parent->id]);
        $sourceChecker = User::factory()->create(['company_id' => $parent->id]);
        $destinationUser = User::factory()->create(['company_id' => $subsidiary->id]);
        $sourceBranch = Branch::create(['company_id' => $parent->id, 'name' => 'IC Source Branch', 'code' => 'IC-SOURCE-BRANCH']);
        $destinationBranch = Branch::create(['company_id' => $subsidiary->id, 'name' => 'IC Destination Branch', 'code' => 'IC-DEST-BRANCH']);
        $sourceWarehouse = $sourceBranch->warehouses()->create(['name' => 'IC Source Warehouse', 'code' => 'IC-SOURCE-WH']);
        $destinationWarehouse = $destinationBranch->warehouses()->create(['name' => 'IC Destination Warehouse', 'code' => 'IC-DEST-WH']);
        $sourceLocation = InventoryLocation::create(['warehouse_id' => $sourceWarehouse->id, 'name' => 'IC Source Bin', 'code' => 'IC-SOURCE-BIN', 'type' => 'bin', 'is_active' => true]);
        $destinationLocation = InventoryLocation::create(['warehouse_id' => $destinationWarehouse->id, 'name' => 'IC Destination Bin', 'code' => 'IC-DEST-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $parent->id, 'name' => 'IC Shared Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'IC Each', 'status' => 1]);
        $category = Category::create(['name' => 'IC Category', 'status' => 1]);
        $product = Product::create(['company_id' => null, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Shared intercompany item', 'sku' => 'IC-SHARED-001', 'purchase_price' => 10, 'is_stock_item' => true, 'status' => 1]);
        InventoryMovement::create(['company_id' => $parent->id, 'product_id' => $product->id, 'location_id' => $sourceLocation->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 10, 'posted_at' => now()]);

        Sanctum::actingAs($sourceCreator, ['inventory:write', 'integration:write']);
        $created = $this->postJson('/api/inventory/intercompany-transfers', [
            'destination_company_id' => $subsidiary->id, 'date' => now()->toDateString(), 'external_reference' => 'IC-TRANSFER-1',
            'lines' => [['product_id' => $product->id, 'source_location_id' => $sourceLocation->id, 'destination_location_id' => $destinationLocation->id, 'quantity' => 3]],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $transferId = $created->json('data.id');

        Sanctum::actingAs($sourceChecker, ['inventory:write', 'integration:write']);
        $this->postJson('/api/inventory/intercompany-transfers/'.$transferId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/inventory/intercompany-transfers/'.$transferId.'/dispatch')->assertOk()->assertJsonPath('status', 'in_transit');
        $this->assertDatabaseHas('inventory_movements', ['company_id' => $parent->id, 'product_id' => $product->id, 'movement_type' => 'transfer_out', 'quantity' => 3]);

        Sanctum::actingAs($destinationUser, ['inventory:write', 'integration:write']);
        $received = $this->postJson('/api/inventory/intercompany-transfers/'.$transferId.'/receive', ['external_reference' => 'IC-RECEIPT-1']);
        $received->assertOk()->assertJsonPath('status', 'received')->assertJsonPath('data.destination_company_id', $subsidiary->id);
        $this->assertDatabaseHas('inventory_movements', ['company_id' => $subsidiary->id, 'product_id' => $product->id, 'movement_type' => 'transfer_in', 'quantity' => 3]);
        $this->assertDatabaseHas('inventory_intercompany_receipts', ['transfer_id' => $transferId, 'company_id' => $subsidiary->id, 'external_reference' => 'IC-RECEIPT-1']);
        $this->assertSame(1, InventoryIntercompanyReceipt::where('transfer_id', $transferId)->count());
        $this->assertSame('received', InventoryIntercompanyTransfer::findOrFail($transferId)->status);

        $this->postJson('/api/inventory/intercompany-transfers/'.$transferId.'/receive', ['external_reference' => 'IC-RECEIPT-1'])
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->assertDatabaseCount('inventory_intercompany_receipts', 1);
    }
}
