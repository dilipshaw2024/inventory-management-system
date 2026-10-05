<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventoryDocument;
use App\Models\InventoryExpiryOverrideRequest;
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

    public function test_unselected_batch_issue_is_allocated_by_fefo_and_persisted(): void
    {
        $company = Company::create(['name' => 'Automatic FEFO Co', 'code' => 'AUTO-FEFO']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'FEFO Branch', 'code' => 'FEFO-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'FEFO Warehouse', 'code' => 'FEFO-WAREHOUSE']);
        $location = $warehouse->locations()->create(['name' => 'FEFO Bin', 'code' => 'FEFO-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'FEFO Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'FEFO Each', 'code' => 'FEFO-EA', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'FEFO Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'FEFO Item', 'sku' => 'FEFO-ITEM', 'quantity' => 10, 'purchase_price' => 5, 'tracking_type' => 'batch', 'status' => 1]);
        $laterExpiry = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'FEFO-LATER', 'location_id' => $location->id, 'expiry_date' => now()->addDays(30)->toDateString()]);
        $earlierExpiry = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'FEFO-EARLIER', 'location_id' => $location->id, 'expiry_date' => now()->addDays(10)->toDateString()]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $laterExpiry->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 5, 'posted_at' => now()->subDay()]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $earlierExpiry->id, 'movement_type' => 'receipt', 'quantity' => 6, 'unit_cost' => 6, 'posted_at' => now()]);
        InventoryCostLayer::create(['product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $laterExpiry->id, 'original_quantity' => 4, 'remaining_quantity' => 4, 'unit_cost' => 5, 'received_at' => now()->subDay()]);
        InventoryCostLayer::create(['product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $earlierExpiry->id, 'original_quantity' => 6, 'remaining_quantity' => 6, 'unit_cost' => 6, 'received_at' => now()]);

        $token = $creator->createToken('automatic-fefo-creator', ['inventory:write'])->plainTextToken;
        $document = $this->withToken($token)->postJson('/api/inventory/documents', [
            'document_type' => 'issue', 'date' => '2026-09-24', 'description' => 'Automatic FEFO issue', 'location_id' => $location->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/documents/'.$document.'/approve')->assertOk()->assertJsonPath('status', 'approved');

        $line = InventoryDocument::findOrFail($document)->lines()->first();
        $this->assertEquals([['batch_no' => 'FEFO-EARLIER', 'quantity' => 5]], $line->batch_allocations);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $document, 'batch_id' => $earlierExpiry->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('inventory_movements', ['reference_id' => $document, 'batch_id' => $laterExpiry->id]);
    }

    public function test_expired_batch_issue_requires_and_consumes_document_scoped_checker_exception(): void
    {
        $company = Company::create(['name' => 'Expiry Exception Co', 'code' => 'EXPIRY-EXCEPTION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Expiry Branch', 'code' => 'EXP-BRANCH']);
        $warehouse = $branch->warehouses()->create(['name' => 'Expiry Warehouse', 'code' => 'EXP-WAREHOUSE']);
        $location = $warehouse->locations()->create(['name' => 'Expiry Bin', 'code' => 'EXP-BIN', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Expiry Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Expiry Each', 'code' => 'EXP-EA', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Expiry Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Expiry Item', 'sku' => 'EXP-ITEM', 'quantity' => 2, 'purchase_price' => 8, 'tracking_type' => 'batch', 'status' => 1]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'EXP-OLD', 'location_id' => $location->id, 'expiry_date' => now()->subDay()->toDateString()]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $batch->id, 'movement_type' => 'receipt', 'quantity' => 2, 'unit_cost' => 8, 'posted_at' => now()->subDays(2)]);
        InventoryCostLayer::create(['product_id' => $product->id, 'location_id' => $location->id, 'batch_id' => $batch->id, 'original_quantity' => 2, 'remaining_quantity' => 2, 'unit_cost' => 8, 'received_at' => now()->subDays(2)]);

        $creatorToken = $creator->createToken('expiry-creator', ['inventory:write'])->plainTextToken;
        $document = $this->withToken($creatorToken)->postJson('/api/inventory/documents', [
            'document_type' => 'issue', 'date' => now()->toDateString(), 'description' => 'Exceptional expiry issue', 'location_id' => $location->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'batch_no' => $batch->batch_no]],
        ])->assertCreated()->json('data.id');

        $override = $this->withToken($creatorToken)->postJson('/api/inventory/documents/'.$document.'/expiry-overrides', [
            'scope' => 'expired', 'reason' => 'Approved customer safety replacement is required.',
        ])->assertCreated()->assertJsonPath('status', 'pending')->json('data.id');
        $this->withToken($creatorToken)->postJson('/api/inventory/documents/'.$document.'/expiry-overrides/'.$override.'/approve', ['decision_reason' => 'not allowed'])->assertStatus(422);

        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $checkerDecision = $this->postJson('/api/inventory/documents/'.$document.'/expiry-overrides/'.$override.'/approve', [
            'decision_reason' => 'Checker approved the documented exception.',
        ]);
        $checkerDecision->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/inventory/documents/'.$document.'/approve')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $document, 'batch_id' => $batch->id, 'movement_type' => 'issue', 'quantity' => 1]);
        $this->assertDatabaseHas('inventory_expiry_override_requests', ['id' => $override, 'status' => 'approved']);
        $this->assertNotNull(InventoryExpiryOverrideRequest::findOrFail($override)->consumed_at);
    }
}
