<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_document_creation_replay_returns_the_original_document(): void
    {
        $company = Company::create(['name' => 'Inventory Document Replay Co', 'code' => 'DOCUMENT-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Document Replay Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Document Replay Each', 'code' => 'EA-DOCUMENT-REPLAY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Document Replay Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Document Replay Item', 'status' => 1, 'is_stock_item' => true]);
        Sanctum::actingAs($user, ['inventory:write']);
        $payload = ['external_reference' => 'DOCUMENT-REPLAY-1', 'document_type' => 'receipt', 'date' => '2026-09-20', 'description' => 'Inbound warehouse receipt', 'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 12]]];

        $created = $this->postJson('/api/inventory/documents', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/inventory/documents', $payload + ['lines' => [['product_id' => $product->id, 'quantity' => 99, 'unit_cost' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, InventoryDocument::where('company_id', $company->id)->count());
    }
}
