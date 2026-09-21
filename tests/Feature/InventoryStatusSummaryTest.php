<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryStatusBalance;
use App\Models\InventoryMovement;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryStatusSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_summary_reports_quantity_and_value_by_disposition(): void
    {
        $company = Company::create(['name' => 'Disposition Co', 'code' => 'DISPOSITION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Disposition Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-DISPOSITION', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Disposition Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Disposition item', 'quantity' => 10, 'purchase_price' => 6, 'status' => 1]);
        InventoryStatusBalance::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => null, 'status' => 'damaged', 'quantity' => 3]);
        InventoryStatusBalance::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => null, 'status' => 'quarantine', 'quantity' => 2]);

        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/status-balances/summary')->assertOk()
            ->assertJsonPath('summary.quantity', 5)
            ->assertJsonPath('summary.value', 30)
            ->assertJsonPath('summary.by_status.damaged.quantity', 3)
            ->assertJsonPath('summary.by_status.damaged.value', 18)
            ->assertJsonPath('summary.by_status.quarantine.quantity', 2)
            ->assertJsonPath('data.0.product_id', $product->id);
    }

    public function test_scrap_status_transfer_can_receive_recovery_material(): void
    {
        $company = Company::create(['name' => 'Disposition Recovery Co', 'code' => 'DISPOSITION-REC']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Recovery Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-RECOVERY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Recovery Category', 'status' => 1]);
        $scrapped = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Damaged source', 'quantity' => 5, 'purchase_price' => 10, 'status' => 1]);
        $recovery = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Recovered material', 'quantity' => 1, 'purchase_price' => 1, 'status' => 1]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200-REC', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        $loss = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5100-REC', 'name' => 'Inventory loss', 'account_type' => 'expense', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory', 'account_id' => $inventory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory_loss', 'account_id' => $loss->id]);
        FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        InventoryStatusBalance::create(['company_id' => $company->id, 'product_id' => $scrapped->id, 'location_id' => null, 'status' => 'blocked', 'quantity' => 2]);

        Sanctum::actingAs($creator, ['inventory:write']);
        $created = $this->postJson('/api/inventory/status-transfers', [
            'transfer_no' => 'ST-RECOVERY-1', 'external_reference' => 'wms-disposition-1001', 'product_id' => $scrapped->id, 'from_status' => 'blocked', 'to_status' => 'scrap',
            'quantity' => 2, 'reason' => 'Damaged material recovery', 'recovery_product_id' => $recovery->id, 'recovery_quantity' => 1.5, 'recovery_unit_cost' => 3,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/inventory/status-transfers', [
            'transfer_no' => 'ST-RECOVERY-REPLAY', 'external_reference' => 'wms-disposition-1001', 'product_id' => $scrapped->id, 'from_status' => 'blocked', 'to_status' => 'scrap',
            'quantity' => 99, 'reason' => 'Replay should not create another transfer',
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created);
        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/status-transfers/'.$created.'/approve')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('products', ['id' => $scrapped->id, 'quantity' => 3]);
        $this->assertDatabaseHas('products', ['id' => $recovery->id, 'quantity' => 2.5, 'purchase_price' => 3]);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $created, 'product_id' => $recovery->id, 'movement_type' => 'receipt', 'quantity' => 1.5]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory_status_transfer.approved', 'auditable_id' => $created]);
        $journals = JournalEntry::where('source_id', $created)->where('source_type', (new \App\Models\InventoryStatusTransfer())->getMorphClass())->with('lines')->get();
        $this->assertCount(2, $journals);
        $this->assertSame(20.0, round((float) $journals->sum(fn ($journal) => $journal->lines->where('account_id', $loss->id)->sum('debit')), 6));
        $this->assertSame(4.5, round((float) $journals->sum(fn ($journal) => $journal->lines->where('account_id', $loss->id)->sum('credit')), 6));
    }

    public function test_status_transfer_approval_returns_unprocessable_for_insufficient_stock(): void
    {
        $company = Company::create(['name' => 'Disposition Validation Co', 'code' => 'DISPOSITION-VALIDATION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Validation Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-VALIDATION', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Validation Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Insufficient source', 'quantity' => 1, 'purchase_price' => 4, 'status' => 1]);

        Sanctum::actingAs($creator, ['inventory:write']);
        $transferId = $this->postJson('/api/inventory/status-transfers', [
            'product_id' => $product->id, 'from_status' => 'available', 'to_status' => 'damaged',
            'quantity' => 2, 'reason' => 'Validation test',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/status-transfers/'.$transferId.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient available stock at the selected location for Insufficient source.');

        $this->assertDatabaseHas('inventory_status_transfers', ['id' => $transferId, 'status' => 'pending']);
        $this->assertDatabaseMissing('inventory_movements', ['reference_id' => $transferId]);
    }
}
