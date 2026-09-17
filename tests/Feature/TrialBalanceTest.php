<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_balance_reports_opening_period_and_closing_posted_balances(): void
    {
        $company = Company::create(['name' => 'Trial Balance Co', 'code' => 'TRIAL-BALANCE']);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1000', 'name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]);
        $sales = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4000', 'name' => 'Sales', 'account_type' => 'income', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $opening = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'TB-OPEN', 'date' => '2026-08-31', 'status' => 'draft']);
        $opening->lines()->createMany([['account_id' => $cash->id, 'debit' => 100, 'credit' => 0], ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100]]);
        $opening->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        $period = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'TB-PERIOD', 'date' => '2026-09-10', 'status' => 'draft']);
        $period->lines()->createMany([['account_id' => $cash->id, 'debit' => 50, 'credit' => 0], ['account_id' => $sales->id, 'debit' => 0, 'credit' => 50]]);
        $period->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        Sanctum::actingAs($user, ['accounting:read']);
        $response = $this->getJson('/api/accounting/trial-balance?from=2026-09-01&to=2026-09-30');
        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.closing_debit', 150)->assertJsonPath('data.0.period_debit', 50)->assertJsonPath('summary.closing_debit', 150)->assertJsonPath('summary.closing_credit', 150);
    }
}
