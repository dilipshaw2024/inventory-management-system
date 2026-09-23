<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\InventoryCostRevaluationRun;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\FiscalPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_fiscal_periods_control_posting_and_close_with_snapshot(): void
    {
        $company = Company::create(['name' => 'Period Co', 'code' => 'PERIOD-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create([
            'company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        Sanctum::actingAs($user, ['integration:write', 'accounting:read', 'accounting:write']);

        $created = $this->postJson('/api/accounting/fiscal-periods', [
            'fiscal_year_id' => $year->id, 'name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31',
        ]);
        $created->assertCreated()->assertJsonPath('status', 'created');
        $this->assertDatabaseMissing('fiscal_periods', ['name' => '2026-02']);
        $this->expectException(\RuntimeException::class);
        app(FiscalPeriodService::class)->assertOpen($company->id, '2026-02-15');
    }

    public function test_period_can_be_closed_and_reopened_with_a_reason(): void
    {
        $company = Company::create(['name' => 'Period Close Co', 'code' => 'PERIOD-CLOSE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create([
            'company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        Sanctum::actingAs($user, ['integration:write', 'accounting:read', 'accounting:write']);
        $period = $this->postJson('/api/accounting/fiscal-periods', [
            'fiscal_year_id' => $year->id, 'name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31',
        ])->json('data');

        $this->getJson('/api/accounting/fiscal-periods/'.$period['id'].'/close-checklist')
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('data.ready_to_close', true)
            ->assertJsonPath('data.checks.3.status', 'will_capture_on_close');
        $this->postJson('/api/accounting/fiscal-periods/'.$period['id'].'/close', ['close_reason' => 'January reconciliation completed.'])
            ->assertOk()->assertJsonPath('status', 'closed');
        $this->assertDatabaseHas('fiscal_periods', ['id' => $period['id'], 'status' => 'closed']);

        $this->postJson('/api/accounting/fiscal-periods/'.$period['id'].'/reopen', ['reopen_reason' => 'Correction required for late supplier document.'])
            ->assertOk()->assertJsonPath('status', 'open');
        $this->assertDatabaseHas('fiscal_periods', ['id' => $period['id'], 'status' => 'open']);
    }

    public function test_fiscal_year_close_requires_bank_reconciliation_through_the_close_date(): void
    {
        $company = Company::create(['name' => 'Bank Close Gate Co', 'code' => 'BANK-CLOSE-GATE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Gate bank', 'currency_code' => 'USD', 'is_active' => true]);
        $line = BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-09-18', 'amount' => 25, 'status' => 'unmatched']);
        Sanctum::actingAs($user, ['integration:write', 'accounting:write']);

        $this->postJson('/api/accounting/fiscal-years/'.$year->id.'/close', ['close_reason' => 'Attempt close with unreconciled bank activity.'])
            ->assertStatus(422)->assertJsonPath('message', 'Close checklist failed: 1 bank statement lines remain unmatched through 2026-12-31.');

        $line->update(['status' => 'matched']);
        BankReconciliation::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'statement_date' => '2026-09-30', 'opening_balance' => 0, 'closing_balance' => 25, 'book_balance' => 25, 'difference' => 0, 'line_count' => 1, 'unmatched_count' => 0, 'status' => 'draft']);
        $this->postJson('/api/accounting/fiscal-years/'.$year->id.'/close', ['close_reason' => 'Attempt close with draft bank reconciliation.'])
            ->assertStatus(422)->assertJsonPath('message', 'Close checklist failed: 1 bank reconciliations remain open through 2026-12-31.');
    }

    public function test_period_close_rejects_pending_inventory_revaluation_runs(): void
    {
        $company = Company::create(['name' => 'Revaluation Close Gate Co', 'code' => 'REVAL-CLOSE-GATE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = \App\Models\FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open']);
        InventoryCostRevaluationRun::create(['company_id' => $company->id, 'as_of_date' => '2026-01-31', 'status' => 'pending', 'total_variance' => 10, 'requested_by' => $user->id]);
        Sanctum::actingAs($user, ['integration:write', 'accounting:write']);

        $this->postJson('/api/accounting/fiscal-periods/'.$period->id.'/close', ['close_reason' => 'Attempt close with pending inventory revaluation.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Close checklist failed: 1 inventory cost revaluation run(s) remain pending through 2026-01-31.');
    }

    public function test_period_close_checklist_previews_blockers_without_mutating_period(): void
    {
        $company = Company::create(['name' => 'Checklist Co', 'code' => 'CHECKLIST-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = \App\Models\FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open']);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Checklist bank', 'currency_code' => 'USD', 'is_active' => true]);
        BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-01-15', 'amount' => 25, 'status' => 'unmatched']);
        Sanctum::actingAs($user, ['accounting:read']);

        $this->getJson('/api/accounting/fiscal-periods/'.$period->id.'/close-checklist')
            ->assertOk()->assertJsonPath('status', 'blocked')->assertJsonPath('data.ready_to_close', false)
            ->assertJsonPath('data.checks.0.key', 'bank_statement_lines')->assertJsonPath('data.checks.0.count', 1);
        $this->assertDatabaseHas('fiscal_periods', ['id' => $period->id, 'status' => 'open']);
    }

    public function test_period_close_settles_profit_to_retained_earnings_and_reverses_on_reopen(): void
    {
        $company = Company::create(['name' => 'Settlement Co', 'code' => 'PERIOD-SETTLE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $period = FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open']);
        $revenue = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4100', 'name' => 'Revenue', 'account_type' => 'income', 'is_active' => true]);
        $expense = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5100', 'name' => 'Expense', 'account_type' => 'expense', 'is_active' => true]);
        $receivable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200', 'name' => 'Receivable', 'account_type' => 'asset', 'is_active' => true]);
        $equity = ChartOfAccount::create(['company_id' => $company->id, 'code' => '3200', 'name' => 'Retained earnings', 'account_type' => 'equity', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'retained_earnings', 'account_id' => $equity->id]);
        Sanctum::actingAs($user, ['integration:write', 'accounting:write']);
        app(\App\Services\AccountingService::class)->post([
            'company_id' => $company->id, 'entry_no' => 'JE-PERIOD-1', 'date' => '2026-01-15', 'description' => 'January activity',
        ], [
            ['account_id' => $expense->id, 'debit' => 40, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1],
            ['account_id' => $receivable->id, 'debit' => 60, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100, 'currency_code' => 'USD', 'exchange_rate' => 1],
        ]);

        $closed = $this->postJson('/api/accounting/fiscal-periods/'.$period->id.'/close', ['close_reason' => 'January financial close.']);
        $closed->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('data.settlement_status', 'posted');
        $settlementId = $closed->json('data.settlement_journal_id');
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlementId, 'account_id' => $equity->id, 'credit' => 60]);

        $reopened = $this->postJson('/api/accounting/fiscal-periods/'.$period->id.'/reopen', ['reopen_reason' => 'Late January correction.']);
        $reopened->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('data.settlement_status', 'reversed');
        $this->assertNotNull($reopened->json('data.settlement_reversal_journal_id'));
    }
}
