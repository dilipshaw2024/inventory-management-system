<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_flow_uses_configured_cash_account_and_classifies_posted_movements(): void
    {
        $company = Company::create(['name' => 'Cash Flow Co', 'code' => 'CASH-FLOW']);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1000', 'name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]);
        $capital = ChartOfAccount::create(['company_id' => $company->id, 'code' => '3000', 'name' => 'Capital', 'account_type' => 'equity', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cash', 'account_id' => $cash->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $opening = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'CF-OPEN', 'date' => '2026-08-31', 'status' => 'draft']);
        $opening->lines()->createMany([['account_id' => $cash->id, 'debit' => 100, 'credit' => 0], ['account_id' => $capital->id, 'debit' => 0, 'credit' => 100]]);
        $opening->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        $period = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'CF-PERIOD', 'date' => '2026-09-10', 'status' => 'draft']);
        $period->lines()->createMany([['account_id' => $cash->id, 'debit' => 50, 'credit' => 0], ['account_id' => $capital->id, 'debit' => 0, 'credit' => 50]]);
        $period->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        Sanctum::actingAs($user, ['accounting:read']);
        $response = $this->getJson('/api/accounting/cash-flow?from=2026-09-01&to=2026-09-30');
        $response->assertOk()->assertJsonPath('summary.opening_cash', 100)->assertJsonPath('summary.financing', 50)->assertJsonPath('summary.net_change', 50)->assertJsonPath('summary.closing_cash', 150)->assertJsonPath('meta.configured', true);
    }
}
