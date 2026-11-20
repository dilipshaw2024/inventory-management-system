<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\DocumentRevision;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_procurement_purchase_order_replay_returns_the_original_order(): void
    {
        $company = Company::create(['name' => 'PO Idempotency Co', 'code' => 'PO-IDEMPOTENCY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'PO Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'PO Each', 'code' => 'EA-PO-IDEM', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'PO Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'PO item', 'status' => 1, 'is_stock_item' => true,
            'can_purchase' => true,
        ]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $payload = [
            'external_reference' => 'PO-EXTERNAL-1', 'supplier_id' => $supplier->id,
            'date' => '2026-09-20', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 7]],
        ];

        $created = $this->postJson('/api/integration/purchase-orders', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.status', 'submitted');
        $revision = DocumentRevision::where('document_type', PurchaseOrder::class)->where('document_id', $created->json('data.id'))->latest('version')->firstOrFail();
        $this->assertSame($product->id, (int) data_get($revision->new_values, 'lines.0.product_id'));
        $this->assertSame('3.000000', (string) data_get($revision->new_values, 'lines.0.ordered_qty'));
        $replayed = $this->postJson('/api/integration/purchase-orders', $payload + ['lines' => [['product_id' => $product->id, 'quantity' => 99, 'unit_price' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, PurchaseOrder::where('company_id', $company->id)->count());
    }

    public function test_submitted_purchase_order_revision_resets_approval_and_records_before_after_lines(): void
    {
        $company = Company::create(['name' => 'PO Revision Co', 'code' => 'PO-REVISION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Revision Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Revision Each', 'code' => 'EA-PO-REV', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Revision Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Revision item', 'status' => 1, 'is_stock_item' => true, 'can_purchase' => true]);
        Sanctum::actingAs($user, ['purchasing:write']);
        $created = $this->postJson('/api/integration/purchase-orders', ['supplier_id' => $supplier->id, 'date' => '2026-09-20', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 7]]])->assertCreated();
        $id = $created->json('data.id');
        $revised = $this->patchJson('/api/integration/purchase-orders/'.$id, ['supplier_id' => $supplier->id, 'date' => '2026-09-21', 'description' => 'Revised commercial terms', 'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 8]]]);
        $revised->assertOk()->assertJsonPath('status', 'pending_approval')->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.lines.0.ordered_qty', '5.000000');
        $revision = DocumentRevision::where('document_type', PurchaseOrder::class)->where('document_id', $id)->latest('version')->firstOrFail();
        $this->assertSame('3.000000', (string) data_get($revision->old_values, 'lines.0.ordered_qty'));
        $this->assertSame('5.000000', (string) data_get($revision->new_values, 'lines.0.ordered_qty'));
        $this->assertSame(1, PurchaseOrder::where('company_id', $company->id)->count());
    }

    public function test_purchase_order_closure_report_exposes_remaining_quantity_and_value(): void
    {
        $company = Company::create(['name' => 'PO Closure Co', 'code' => 'PO-CLOSURE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Closure Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Closure Each', 'code' => 'EA-PO-CLOSE', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Closure Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Closure item', 'status' => 1, 'is_stock_item' => true, 'can_purchase' => true]);
        Sanctum::actingAs($user, ['purchasing:write', 'purchasing:read']);
        $created = $this->postJson('/api/integration/purchase-orders', ['supplier_id' => $supplier->id, 'date' => '2026-09-20', 'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 8]]])->assertCreated();
        $report = $this->getJson('/api/integration/purchase-orders/closure-report?open_only=1');
        $report->assertOk()->assertJsonPath('summary.orders', 1)->assertJsonPath('data.0.remaining_quantity', 5)->assertJsonPath('data.0.remaining_value', 40)->assertJsonPath('data.0.closure_status', 'open');
        $this->assertSame($created->json('data.id'), $report->json('data.0.id'));
    }
}
