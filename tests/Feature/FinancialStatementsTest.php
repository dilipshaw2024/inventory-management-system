<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_statements_return_period_profit_and_as_of_balance_sheet(): void
    {
        $company = Company::create(['name' => 'Statements Co', 'code' => 'STATEMENTS']);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1000', 'name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]);
        $capital = ChartOfAccount::create(['company_id' => $company->id, 'code' => '3000', 'name' => 'Capital', 'account_type' => 'equity', 'is_active' => true]);
        $income = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4000', 'name' => 'Sales', 'account_type' => 'income', 'is_active' => true]);
        $expense = ChartOfAccount::create(['company_id' => $company->id, 'code' => '5000', 'name' => 'Expense', 'account_type' => 'expense', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'FS-1', 'date' => '2026-09-10', 'status' => 'draft']);
        $entry->lines()->createMany([['account_id' => $cash->id, 'debit' => 150, 'credit' => 0], ['account_id' => $capital->id, 'debit' => 0, 'credit' => 100], ['account_id' => $income->id, 'debit' => 0, 'credit' => 80], ['account_id' => $expense->id, 'debit' => 30, 'credit' => 0]]);
        $entry->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        Sanctum::actingAs($user, ['accounting:read']);
        $response = $this->getJson('/api/accounting/financial-statements?from=2026-09-01&to=2026-09-30');
        $response->assertOk()->assertJsonPath('summary.income', 80)->assertJsonPath('summary.expenses', 30)->assertJsonPath('summary.net_income', 50)->assertJsonPath('summary.assets', 150)->assertJsonPath('summary.equity_and_net_income', 150)->assertJsonPath('summary.balance_difference', 0);
    }
}
