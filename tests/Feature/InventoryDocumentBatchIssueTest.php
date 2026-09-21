<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventoryDocument;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDocumentBatchIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_issue_can_post_one_line_across_multiple_batches(): void
    {
        $company = Company::create(['name' => 'Multi-Batch Co', 'code' => 'MULTI-BATCH']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Multi-Batch Branch', 'code' => 'MB-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Multi-Batch Warehouse', 'code' => 'MB-WAREHOUSE']);
        $location = $warehouse->locations()->create(['name' => 'Multi-Batch Bin', 'code' => 'MB-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Multi-Batch Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Multi-Batch Each', 'code' => 'MB-EA', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Multi-Batch Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Multi-Batch Item', 'quantity' => 10, 'purchase_price' => 5, 'status' => 1]);
        $first = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'MB-1', 'location_id' => $location->id]);
        $second = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'MB-2', 'location_id' => $location->id]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $first->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 5, 'posted_at' => now()->subDay()]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $second->id, 'movement_type' => 'receipt', 'quantity' => 6, 'unit_cost' => 6, 'posted_at' => now()]);
        InventoryCostLayer::create(['product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $first->id, 'original_quantity' => 4, 'remaining_quantity' => 4, 'unit_cost' => 5, 'received_at' => now()->subDay()]);
        InventoryCostLayer::create(['product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $second->id, 'original_quantity' => 6, 'remaining_quantity' => 6, 'unit_cost' => 6, 'received_at' => now()]);

        $token = $creator->createToken('multi-batch-creator', ['inventory:write'])->plainTextToken;
        $document = $this->withToken($token)->postJson('/api/inventory/documents', [
            'document_type' => 'issue', 'date' => '2026-09-19', 'description' => 'Split batch issue', 'location_id' => $location->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 7, 'batch_allocations' => [
                ['batch_no' => 'MB-1', 'quantity' => 4], ['batch_no' => 'MB-2', 'quantity' => 3],
            ]]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/documents/'.$document.'/approve')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $document, 'batch_id' => $first->id, 'quantity' => 4]);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $document, 'batch_id' => $second->id, 'quantity' => 3]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 3]);
        $this->assertSame(2, InventoryDocument::findOrFail($document)->lines()->first()->batch_allocations ? count(InventoryDocument::findOrFail($document)->lines()->first()->batch_allocations) : 0);
    }
}
