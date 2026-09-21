<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\CostCenterBudget;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CostCenterBudgetIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_clients_can_read_budget_vs_actual_with_alert_status(): void
    {
        $company = Company::create(['name' => 'Budget API Co', 'code' => 'BUDGET-API']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $center = CostCenter::create(['company_id' => $company->id, 'code' => 'OPS', 'name' => 'Operations', 'is_active' => true]);
        $expense = ChartOfAccount::create(['company_id' => $company->id, 'code' => '6100', 'name' => 'Operations expense', 'account_type' => 'expense', 'is_active' => true]);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1100', 'name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]);
        CostCenterBudget::create(['company_id' => $company->id, 'cost_center_id' => $center->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'budget_amount' => 100, 'currency_code' => 'USD']);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'BUDGET-1', 'date' => '2026-09-18', 'status' => 'draft']);
        $journal->lines()->createMany([
            ['account_id' => $expense->id, 'cost_center_id' => $center->id, 'debit' => 90, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 90],
        ]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);

        Sanctum::actingAs($user, ['accounting:read']);
        $this->getJson('/api/accounting/cost-centers/budget-vs-actual?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('summary.budget', 100)->assertJsonPath('summary.actual', 90)
            ->assertJsonPath('summary.warning_count', 1)->assertJsonPath('data.0.status', 'warning')
            ->assertJsonPath('meta.alert_threshold', 0.8);
    }

    public function test_accounting_clients_can_synchronize_and_deactivate_budgets_idempotently(): void
    {
        $company = Company::create(['name' => 'Budget Sync Co', 'code' => 'BUDGET-SYNC']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $center = CostCenter::create(['company_id' => $company->id, 'code' => 'SYNC', 'name' => 'Sync center', 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);
        $payload = ['cost_center_id' => $center->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'budget_amount' => 250, 'currency_code' => 'usd', 'external_reference' => 'ERP-BUDGET-1'];
        $created = $this->postJson('/api/accounting/cost-centers/budgets', $payload)->assertCreated()->assertJsonPath('status', 'created');
        $budgetId = $created->json('data.id');
        $this->postJson('/api/accounting/cost-centers/budgets', $payload)->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->getJson('/api/accounting/cost-centers/budgets')->assertOk()->assertJsonPath('data.0.external_reference', 'ERP-BUDGET-1');
        $this->postJson('/api/accounting/cost-centers/budgets/'.$budgetId.'/deactivate')->assertOk()->assertJsonPath('status', 'deactivated');
        $this->assertDatabaseHas('cost_center_budgets', ['id' => $budgetId, 'is_active' => false]);
    }
}
