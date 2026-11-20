<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchRecallIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalled_batch_is_audited_and_cannot_be_issued_until_released(): void
    {
        $company = Company::create(['name' => 'Recall Tenant', 'code' => 'RECALL-TENANT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Recall Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Recall Goods', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Recalled goods', 'sku' => 'RECALL-001',
            'tracking_type' => 'batch', 'quantity' => 1, 'status' => 1, 'is_stock_item' => true, 'purchase_price' => 2,
        ]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'RECALL-LOT-001']);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
        app(InventoryLedgerService::class)->post($product->id, 'receipt', 1, 10, null, null, 'Recall test receipt', $user->id, $batch->id);

        $this->postJson('/api/inventory/batches/'.$batch->id.'/recall', [
            'recall_reference' => 'RECALL-2026-001', 'recall_reason' => 'Quality investigation',
        ])->assertOk()->assertJsonPath('status', 'recalled')->assertJsonPath('data.recall_reference', 'RECALL-2026-001');
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory_batch.recalled', 'auditable_id' => $batch->id]);
        $this->getJson('/api/inventory/batches/recalls')->assertOk()->assertJsonPath('data.0.id', $batch->id)->assertJsonPath('data.0.recall_status', 'active')->assertJsonPath('data.0.stock_quantity', 1)->assertJsonPath('data.0.stock_value', 10)->assertJsonPath('data.0.value_at_risk', 10)->assertJsonPath('meta.status', 'active');
        $permission = Permission::create(['code' => 'reports.view', 'name' => 'Reports view', 'module' => 'reports']);
        $inventoryPermission = Permission::create(['code' => 'inventory.post', 'name' => 'Inventory post', 'module' => 'inventory']);
        $role = Role::create(['code' => 'recall-report-viewer', 'name' => 'Recall report viewer', 'is_active' => true]);
        $role->permissions()->attach([$permission->id, $inventoryPermission->id]);
        $user->roles()->attach($role->id);
        $this->actingAs($user)->get('/stock/recalls')->assertOk()->assertSee('RECALL-2026-001')->assertSee('10.00');
        try {
            app(InventoryLedgerService::class)->post($product->id, 'issue', 1, 10, null, null, 'Blocked automatic recall issue', $user->id);
            self::fail('A recalled batch was issued.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Insufficient non-recalled batch stock', $exception->getMessage());
        }
        $this->actingAs($user)->post('/stock/recalls/'.$batch->id.'/release', ['release_reason' => 'Browser review completed'])->assertRedirect();
        $this->assertNotNull($batch->fresh()->recall_released_at);
    }

    public function test_recall_release_restores_outbound_eligibility_and_is_tenant_scoped(): void
    {
        $company = Company::create(['name' => 'Recall Release Tenant', 'code' => 'RECALL-RELEASE']);
        $otherCompany = Company::create(['name' => 'Recall Other Tenant', 'code' => 'RECALL-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Release Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Piece', 'status' => 1]);
        $category = Category::create(['name' => 'Release Goods', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Release goods', 'sku' => 'RECALL-002', 'tracking_type' => 'batch', 'quantity' => 1, 'status' => 1, 'is_stock_item' => true]);
        $otherSupplier = Supplier::create(['company_id' => $otherCompany->id, 'name' => 'Other Supplier', 'is_active' => true]);
        $otherProduct = Product::create(['company_id' => $otherCompany->id, 'supplier_id' => $otherSupplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Other goods', 'sku' => 'RECALL-003', 'tracking_type' => 'batch', 'quantity' => 1, 'status' => 1, 'is_stock_item' => true]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'RECALL-LOT-002']);
        $otherBatch = InventoryBatch::create(['product_id' => $otherProduct->id, 'batch_no' => 'RECALL-LOT-003']);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
        app(InventoryLedgerService::class)->post($product->id, 'receipt', 1, 10, null, null, 'Release test receipt', $user->id, $batch->id);

        $this->postJson('/api/inventory/batches/'.$otherBatch->id.'/recall', ['recall_reference' => 'X', 'recall_reason' => 'Cross tenant'])->assertNotFound();
        $this->postJson('/api/inventory/batches/'.$batch->id.'/recall', ['recall_reference' => 'RECALL-2026-002', 'recall_reason' => 'Investigation'])->assertOk();
        $this->postJson('/api/inventory/batches/'.$batch->id.'/recall/release', ['release_reason' => 'Investigation closed'])->assertOk()->assertJsonPath('status', 'recall_released');
        $this->assertNotNull($batch->fresh()->recall_released_at);
        $this->getJson('/api/inventory/batches/recalls?status=released')->assertOk()->assertJsonPath('data.0.id', $batch->id)->assertJsonPath('data.0.recall_status', 'released')->assertJsonPath('meta.status', 'released');
        $movement = app(InventoryLedgerService::class)->post($product->id, 'issue', 1, 10, null, null, 'Released recall issue', $user->id, $batch->id);
        $this->assertSame('issue', $movement->movement_type);
    }
}
