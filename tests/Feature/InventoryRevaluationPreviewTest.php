<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\Category;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\Department;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryRevaluationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_revaluation_preview_reports_standard_cost_variance_without_mutation(): void
    {
        $company = Company::create(['name' => 'Revaluation Co', 'code' => 'REVAL-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Revaluation Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Revaluation Each', 'status' => 1]);
        $category = Category::create(['name' => 'Revaluation Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Revaluation item', 'sku' => 'REVAL-ITEM', 'status' => 1,
            'costing_method' => 'standard', 'standard_cost' => 8, 'quantity' => 5,
        ]);
        $layer = InventoryCostLayer::create([
            'product_id' => $product->id, 'original_quantity' => 5, 'remaining_quantity' => 5,
            'unit_cost' => 6, 'received_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/valuation/revaluation-preview?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.current_value', 30)
            ->assertJsonPath('data.0.target_value', 40)
            ->assertJsonPath('data.0.variance_amount', 10)
            ->assertJsonPath('data.0.revaluation_required', true)
            ->assertJsonPath('data.0.lines.0.layer_id', $layer->id);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 6]);
    }

    public function test_standard_cost_variance_reports_receipt_and_issue_actuals(): void
    {
        $company = Company::create(['name' => 'Standard Variance Co', 'code' => 'STD-VAR']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Standard Variance Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Standard Variance Each', 'status' => 1]);
        $category = Category::create(['name' => 'Standard Variance Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Standard variance item', 'sku' => 'STD-VAR-ITEM', 'status' => 1, 'quantity' => 1,
            'costing_method' => 'standard', 'standard_cost' => 10, 'purchase_price' => 8,
        ]);
        $receipt = InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt',
            'quantity' => 2, 'unit_cost' => 12, 'posted_at' => now()->subDays(2),
        ]);
        $directLayer = InventoryCostLayer::create([
            'product_id' => $product->id, 'original_quantity' => 1, 'remaining_quantity' => 1,
            'unit_cost' => 8, 'received_at' => now()->subDays(2),
        ]);
        Sanctum::actingAs($user, ['inventory:read']);
        $standardTotal = app(\App\Services\InventoryCostingService::class)->consume($product->id, 1);
        $this->assertSame(10.0, $standardTotal);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $directLayer->id, 'remaining_quantity' => 0]);
        $this->assertDatabaseHas('inventory_cost_consumptions', ['cost_layer_id' => $directLayer->id, 'unit_cost' => 8, 'total_cost' => 8]);

        $layer = InventoryCostLayer::create([
            'product_id' => $product->id, 'original_quantity' => 1, 'remaining_quantity' => 0,
            'unit_cost' => 8, 'received_at' => now()->subDay(),
        ]);
        $issue = InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue',
            'quantity' => 1, 'unit_cost' => 10, 'posted_at' => now()->subDay(),
        ]);
        InventoryCostConsumption::create([
            'cost_layer_id' => $layer->id, 'product_id' => $product->id, 'movement_id' => $issue->id,
            'quantity' => 1, 'unit_cost' => 8, 'total_cost' => 8, 'costing_method' => 'standard',
        ]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/valuation/standard-cost-variance?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString());
        $response->assertOk()
            ->assertJsonPath('summary.movement_count', 2)
            ->assertJsonPath('summary.inbound_count', 1)
            ->assertJsonPath('summary.outbound_count', 1)
            ->assertJsonPath('summary.variance_amount', 2)
            ->assertJsonPath('summary.above_standard_count', 1)
            ->assertJsonPath('summary.below_standard_count', 1);
        $rows = collect($response->json('data'))->keyBy('movement_id');
        $this->assertSame(4.0, (float) $rows->get($receipt->id)['variance_amount']);
        $this->assertSame(-2.0, (float) $rows->get($issue->id)['variance_amount']);
        $this->assertSame(8.0, (float) $rows->get($issue->id)['actual_unit_cost']);
    }

    public function test_standard_cost_variance_can_post_and_replay_settlement_journal(): void
    {
        $company = Company::create(['name' => 'Standard Posting Co', 'code' => 'STD-POST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Standard Posting Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Standard Posting Each', 'status' => 1]);
        $category = Category::create(['name' => 'Standard Posting Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Standard posting item', 'sku' => 'STD-POST-ITEM', 'status' => 1,
            'costing_method' => 'standard', 'standard_cost' => 10, 'quantity' => 2,
        ]);
        $movement = InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt',
            'quantity' => 2, 'unit_cost' => 12, 'posted_at' => now()->subDay(),
        ]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1400', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        $variance = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5190', 'name' => 'Standard Cost Variance', 'account_type' => 'expense', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory', 'account_id' => $inventory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory_standard_variance', 'account_id' => $variance->id]);

        Sanctum::actingAs($user, ['inventory:write']);
        $first = $this->postJson('/api/inventory/valuation/standard-cost-variance/post', ['movement_id' => $movement->id])
            ->assertCreated()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('meta.idempotent', true);
        $journalId = $first->json('data.id');
        $first->assertJsonPath('data.lines.0.debit', 4);
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'external_reference' => 'STD-VARIANCE-MOVEMENT-'.$movement->id]);
        $this->assertSame(1, \App\Models\JournalEntry::where('company_id', $company->id)->where('external_reference', 'STD-VARIANCE-MOVEMENT-'.$movement->id)->count());

        $this->postJson('/api/inventory/valuation/standard-cost-variance/post', ['movement_id' => $movement->id])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_ignored')
            ->assertJsonPath('data.id', $journalId);
        $this->assertSame(1, \App\Models\JournalEntry::where('company_id', $company->id)->where('external_reference', 'STD-VARIANCE-MOVEMENT-'.$movement->id)->count());
    }

    public function test_revaluation_run_requires_independent_approval_and_updates_open_layer(): void
    {
        $company = Company::create(['name' => 'Revaluation Approval Co', 'code' => 'REVAL-APPROVAL']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Approval Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Approval Each', 'status' => 1]);
        $category = Category::create(['name' => 'Approval Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Approval item', 'status' => 1, 'costing_method' => 'standard', 'standard_cost' => 10, 'quantity' => 3]);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 3, 'remaining_quantity' => 3, 'unit_cost' => 7, 'received_at' => now()]);

        Sanctum::actingAs($requester, ['inventory:write']);
        $created = $this->postJson('/api/inventory/valuation/revaluations', ['external_reference' => 'REVAL-RUN-1']);
        $created->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('data.status', 'pending');
        $runId = $created->json('data.id');
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/approve')->assertStatus(422);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 7]);

        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/approve')->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('data.accounting_status', 'missing_mapping');
        $this->assertDatabaseHas('inventory_cost_revaluation_runs', ['id' => $runId, 'status' => 'approved', 'accounting_status' => 'missing_mapping']);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 10]);
        $this->postJson('/api/inventory/valuation/revaluations', ['external_reference' => 'REVAL-RUN-1'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        Sanctum::actingAs($requester, ['inventory:write']);
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/reverse', ['reversal_reason' => 'Correction required'])->assertOk()->assertJsonPath('status', 'reversed');
        $this->assertDatabaseHas('inventory_cost_revaluation_runs', ['id' => $runId, 'status' => 'reversed', 'reversal_reason' => 'Correction required']);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 7]);
    }

    public function test_valuation_can_filter_layers_by_tenant_financial_dimensions(): void
    {
        $company = Company::create(['name' => 'Valuation Dimensions Co', 'code' => 'VAL-DIM-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Valuation Dimensions Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Valuation Dimensions Each', 'status' => 1]);
        $category = Category::create(['name' => 'Valuation Dimensions Category', 'status' => 1]);
        $department = Department::create(['company_id' => $company->id, 'code' => 'DEPT-VAL-DIM', 'name' => 'Operations']);
        $costCenter = CostCenter::create(['company_id' => $company->id, 'code' => 'CC-VAL-DIM', 'name' => 'Valuation Operations', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Dimension item', 'sku' => 'VAL-DIM-ITEM', 'status' => 1]);
        $movement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 12, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'posted_at' => now()->subDay()]);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'original_quantity' => 4, 'remaining_quantity' => 4, 'unit_cost' => 12, 'received_at' => now()->subDay(), 'source_type' => $movement->getMorphClass(), 'source_id' => $movement->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY VAL DIM', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open']);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/valuation?department_id='.$department->id.'&cost_center_id='.$costCenter->id);
        $response->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->assertSame(48.0, (float) $response->json('data.0.ledger_value'));
        $this->assertSame(4.0, (float) $response->json('data.0.valuation_quantity'));
        $this->getJson('/api/inventory/valuation?fiscal_period_id='.$period->id.'&department_id='.$department->id.'&cost_center_id='.$costCenter->id)
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/inventory/valuation?fiscal_period_id='.$period->id.'&as_of=2026-09-30')->assertStatus(422);
        $this->getJson('/api/inventory/valuation?department_id=999999')->assertStatus(422);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id]);
    }

    public function test_revaluation_reconciliation_matches_linked_posted_journal(): void
    {
        $company = Company::create(['name' => 'Revaluation Reconciliation Co', 'code' => 'REVAL-RECON']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Reconciliation Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Reconciliation Each', 'status' => 1]);
        $category = Category::create(['name' => 'Reconciliation Category', 'status' => 1]);
        $department = Department::create(['company_id' => $company->id, 'code' => 'DEPT-REVAL-RECON', 'name' => 'Revaluation Operations']);
        $costCenter = CostCenter::create(['company_id' => $company->id, 'code' => 'CC-REVAL-RECON', 'name' => 'Revaluation Cost Center', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Reconciliation item', 'sku' => 'REVAL-RECON-ITEM', 'status' => 1, 'costing_method' => 'standard', 'standard_cost' => 10, 'quantity' => 3]);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'original_quantity' => 3, 'remaining_quantity' => 3, 'unit_cost' => 7, 'received_at' => now()]);
        $inventory = ChartOfAccount::create(['company_id' => $company->id, 'code' => 'REVAL-1300', 'name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]);
        $gain = ChartOfAccount::create(['company_id' => $company->id, 'code' => 'REVAL-4900', 'name' => 'Revaluation gain', 'account_type' => 'income', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory', 'account_id' => $inventory->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'inventory_revaluation_gain', 'account_id' => $gain->id]);

        Sanctum::actingAs($requester, ['inventory:write']);
        $runId = $this->postJson('/api/inventory/valuation/revaluations', ['external_reference' => 'REVAL-RECON-1'])->assertCreated()->json('data.id');
        Sanctum::actingAs($checker, ['inventory:write']);
        $approved = $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/approve')->assertOk()->assertJsonPath('data.accounting_status', 'posted');
        $journalId = $approved->json('data.journal_entry_id');
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $inventory->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'debit' => 9]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $gain->id, 'department_id' => $department->id, 'cost_center_id' => $costCenter->id, 'credit' => 9]);

        Sanctum::actingAs($checker, ['inventory:read']);
        $this->getJson('/api/inventory/valuation/revaluation-reconciliation?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('summary.revaluations', 1)
            ->assertJsonPath('summary.expected_value', 9)
            ->assertJsonPath('summary.posted_value', 9)
            ->assertJsonPath('data.0.reconciliation_status', 'reconciled')
            ->assertJsonPath('data.0.revaluation_id', $runId);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 10]);
    }

    public function test_accounting_period_valuation_reconciles_opening_movements_and_closing_layers(): void
    {
        $company = Company::create(['name' => 'Period Valuation Co', 'code' => 'PERIOD-VAL-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Period Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Period Each', 'status' => 1]);
        $category = Category::create(['name' => 'Period Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Period item', 'sku' => 'PERIOD-ITEM', 'status' => 1]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY PERIOD', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open']);
        $openingMovement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 10, 'unit_cost' => 5, 'posted_at' => '2026-08-31 10:00:00']);
        $openingLayer = InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 10, 'remaining_quantity' => 8, 'unit_cost' => 5, 'source_type' => $openingMovement->getMorphClass(), 'source_id' => $openingMovement->id, 'received_at' => '2026-08-31 10:00:00']);
        $receiptMovement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 4, 'unit_cost' => 8, 'posted_at' => '2026-09-05 10:00:00']);
        InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 4, 'remaining_quantity' => 4, 'unit_cost' => 8, 'source_type' => $receiptMovement->getMorphClass(), 'source_id' => $receiptMovement->id, 'received_at' => '2026-09-05 10:00:00']);
        $issueMovement = InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'issue', 'quantity' => 2, 'unit_cost' => 5, 'posted_at' => '2026-09-10 10:00:00']);
        InventoryCostConsumption::create(['cost_layer_id' => $openingLayer->id, 'product_id' => $product->id, 'movement_id' => $issueMovement->id, 'quantity' => 2, 'unit_cost' => 5, 'total_cost' => 10, 'costing_method' => 'fifo']);
        InventoryMovementAllocation::create(['movement_id' => $issueMovement->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 5]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/valuation/accounting-period?fiscal_period_id='.$period->id);

        $response->assertOk()
            ->assertJsonPath('period.id', $period->id)
            ->assertJsonPath('summary.opening_value', 50)
            ->assertJsonPath('summary.inbound_value', 32)
            ->assertJsonPath('summary.outbound_value', 10)
            ->assertJsonPath('summary.closing_value', 72)
            ->assertJsonPath('summary.unexplained_variance', 0)
            ->assertJsonPath('data.0.closing_source', 'cost_layers');
    }
}
