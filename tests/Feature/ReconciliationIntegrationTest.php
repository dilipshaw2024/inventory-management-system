<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\InventoryMovement;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReconciliationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_clients_can_read_tenant_reconciliation_status(): void
    {
        $company = Company::create(['name' => 'Reconciliation API Co', 'code' => 'RECON-API']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1300', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory', 'account_id' => $inventory->id]);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'RECON-1', 'date' => '2026-09-18', 'status' => 'draft']);
        $journal->lines()->create(['account_id' => $inventory->id, 'debit' => 0, 'credit' => 0]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        Sanctum::actingAs($user, ['accounting:read']);
        $this->getJson('/api/accounting/reconciliation?to=2026-09-18')
            ->assertOk()->assertJsonPath('summary.status', 'needs_mapping')
            ->assertJsonPath('summary.missing_mapping_count', 2)->assertJsonPath('meta.to', '2026-09-18')
            ->assertJsonCount(3, 'data');
    }

    public function test_cogs_reconciliation_compares_issue_cost_to_mapped_journal(): void
    {
        $company = Company::create(['name' => 'COGS Reconciliation Co', 'code' => 'COGS-RECON']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'COGS supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'COGS category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'COGS item', 'quantity' => 0, 'status' => 1]);
        $cogs = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5000', 'name' => 'COGS', 'account_type' => 'expense', 'is_active' => true]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1300', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cogs', 'account_id' => $cogs->id]);
        $movement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 12.5, 'posted_at' => '2026-09-18 10:00:00']);
        $return = InventoryReturn::create(['company_id' => $company->id, 'return_no' => 'RET-COGS-1', 'return_type' => 'sales', 'date' => '2026-09-18', 'reason_code' => 'customer_return', 'status' => 'approved']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'return_in', 'quantity' => 1, 'unit_cost' => 12.5, 'reference_type' => $return->getMorphClass(), 'reference_id' => $return->id, 'posted_at' => '2026-09-18 11:00:00']);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'JE-COGS-1', 'date' => '2026-09-18', 'description' => 'COGS posting', 'status' => 'draft']);
        $journal->lines()->createMany([['account_id' => $cogs->id, 'debit' => 25, 'credit' => 12.5], ['account_id' => $inventory->id, 'debit' => 12.5, 'credit' => 25]]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);

        Sanctum::actingAs($user, ['accounting:read']);
        $this->getJson('/api/accounting/cogs-reconciliation?from=2026-09-01&to=2026-09-30&product_id='.$product->id)
            ->assertOk()->assertJsonPath('summary.status', 'reconciled')
            ->assertJsonPath('summary.expected_cogs', 12.5)->assertJsonPath('summary.journal_net', 12.5)
            ->assertJsonPath('summary.variance', 0)->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.quantity_issued', 2)->assertJsonPath('data.0.quantity_returned', 1)
            ->assertJsonPath('meta.product_id', $product->id);
        $this->getJson('/api/accounting/cogs-reconciliation?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('meta.source_level_matching', true)
            ->assertJsonCount(3, 'source_reconciliation');
    }

    public function test_sales_reconciliation_allocates_invoice_discount_to_product_lines(): void
    {
        $company = Company::create(['name' => 'Sales Reconciliation Co', 'code' => 'SALES-RECON']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Sales supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Sales Each', 'status' => 1]);
        $category = Category::create(['name' => 'Sales category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Sales item', 'quantity' => 0, 'status' => 1]);
        $revenue = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'INV-SALES-1', 'date' => '2026-09-18', 'status' => 1, 'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100]);
        $invoice->invoice_details()->create(['date' => '2026-09-18', 'product_id' => $product->id, 'selling_qty' => 2, 'unit_price' => 60, 'selling_price' => 120, 'tax_rate' => 0, 'tax_amount' => 0, 'status' => 1]);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'JE-SALES-1', 'date' => '2026-09-18', 'description' => 'Sales revenue posting', 'status' => 'draft']);
        $journal->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);

        Sanctum::actingAs($user, ['accounting:read']);
        $this->getJson('/api/accounting/sales-reconciliation?from=2026-09-01&to=2026-09-30&product_id='.$product->id)
            ->assertOk()->assertJsonPath('summary.status', 'reconciled')
            ->assertJsonPath('summary.expected_net_revenue', 100)->assertJsonPath('summary.journal_net_revenue', 100)
            ->assertJsonPath('summary.variance', 0)->assertJsonPath('data.0.discount', 20)
            ->assertJsonPath('meta.product_id', $product->id);
    }
}
