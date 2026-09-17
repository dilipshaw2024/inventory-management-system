<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
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
}
