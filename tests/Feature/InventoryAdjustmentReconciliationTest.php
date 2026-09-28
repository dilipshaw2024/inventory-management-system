<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAdjustmentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_reconciliation_reports_posted_and_missing_journals(): void
    {
        $company = Company::create(['name' => 'Adjustment Reconciliation Co', 'code' => 'ADJ-RECON']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Adjustment supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Adjustment category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Adjusted item', 'quantity' => 0, 'status' => 1]);
        $account = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        $loss = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5100', 'name' => 'Inventory loss', 'account_type' => 'expense', 'is_active' => true]);
        $adjustment = InventoryAdjustment::create(['company_id' => $company->id, 'adjustment_no' => 'ADJ-RECON-1', 'date' => '2026-09-17', 'reason_code' => 'damage', 'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'adjustment_out', 'quantity' => 2, 'unit_cost' => 5, 'reference_type' => $adjustment->getMorphClass(), 'reference_id' => $adjustment->id, 'created_by' => $user->id]);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'JE-ADJ-RECON-1', 'date' => '2026-09-17', 'source_type' => $adjustment->getMorphClass(), 'source_id' => $adjustment->id, 'description' => 'Adjustment accounting', 'status' => 'draft']);
        $journal->lines()->createMany([['account_id' => $loss->id, 'debit' => 10, 'credit' => 0], ['account_id' => $account->id, 'debit' => 0, 'credit' => 10]]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        $missing = InventoryAdjustment::create(['company_id' => $company->id, 'adjustment_no' => 'ADJ-RECON-2', 'date' => '2026-09-17', 'reason_code' => 'damage', 'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'adjustment_out', 'quantity' => 1, 'unit_cost' => 7, 'reference_type' => $missing->getMorphClass(), 'reference_id' => $missing->id, 'created_by' => $user->id]);

        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/adjustment-reconciliation?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('summary.adjustments', 2)->assertJsonPath('summary.expected_value', 17)
            ->assertJsonPath('summary.posted_value', 10)->assertJsonPath('summary.variance', 7)
            ->assertJsonPath('summary.missing_journal_count', 1)->assertJsonPath('data.0.reconciliation_status', 'reconciled')
            ->assertJsonPath('data.1.reconciliation_status', 'missing_journal');
    }

    public function test_approved_adjustment_carries_dimensions_to_movement_and_journal(): void
    {
        $company = Company::create(['name' => 'Adjustment Dimensions Co', 'code' => 'ADJ-DIM']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Dimension supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dimension each', 'status' => 1]);
        $category = Category::create(['name' => 'Dimension category', 'status' => 1]);
        $department = Department::create(['company_id' => $company->id, 'code' => 'DEPT-ADJ-DIM', 'name' => 'Adjustment Operations']);
        $costCenter = CostCenter::create(['company_id' => $company->id, 'code' => 'CC-ADJ-DIM', 'name' => 'Adjustment Cost Center', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Dimension adjusted item', 'sku' => 'ADJ-DIM-ITEM', 'quantity' => 0, 'status' => 1]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => 'ADJ-DIM-1300', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        $grni = ChartOfAccount::create(['company_id' => $company->id, 'code' => 'ADJ-DIM-2100', 'name' => 'GRNI', 'account_type' => 'liability', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory', 'account_id' => $inventory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'grni', 'account_id' => $grni->id]);

        Sanctum::actingAs($requester, ['inventory:write']);
        $adjustment = $this->postJson('/api/inventory/adjustments', [
            'external_reference' => 'ADJ-DIM-1', 'date' => now()->toDateString(), 'reason_code' => 'opening_stock',
            'lines' => [['product_id' => $product->id, 'direction' => 'in', 'quantity' => 2, 'unit_cost' => 11, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id]],
        ])->assertCreated();
        $adjustmentId = $adjustment->json('data.id');
        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/adjustments/'.$adjustmentId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $adjustmentId, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id]);
        $journalId = \App\Models\JournalEntry::where('source_id', $adjustmentId)->where('status', 'posted')->value('id');
        $this->assertNotNull($journalId);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $inventory->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'debit' => 22]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $grni->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'credit' => 22]);
        Sanctum::actingAs($checker, ['inventory:read']);
        $this->getJson('/api/inventory/adjustment-reconciliation?from='.now()->toDateString().'&to='.now()->toDateString().'&department_id='.$department->id.'&cost_center_id='.$costCenter->id)
            ->assertOk()->assertJsonPath('meta.department_id', $department->id)->assertJsonPath('meta.cost_center_id', $costCenter->id)
            ->assertJsonPath('summary.expected_value', 22)->assertJsonPath('summary.posted_value', 22)->assertJsonPath('data.0.valuation_source', 'movement_cost_layer_allocations')->assertJsonPath('data.0.reconciliation_status', 'reconciled');
    }
}
