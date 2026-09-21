<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsolidatedReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_company_can_read_consolidated_trial_balance_for_active_subsidiaries(): void
    {
        $parent = Company::create(['name' => 'Consolidated Parent', 'code' => 'CON-PARENT', 'base_currency' => 'USD', 'consolidation_currency' => 'USD']);
        $child = Company::create(['name' => 'Consolidated Subsidiary', 'code' => 'CON-CHILD', 'base_currency' => 'USD', 'parent_company_id' => $parent->id]);
        $user = User::factory()->create(['company_id' => $parent->id]);
        $parentAccount = ChartOfAccount::create(['company_id' => $parent->id, 'code' => '4000', 'name' => 'Revenue', 'account_type' => 'income', 'is_active' => true]);
        $eliminationAccount = ChartOfAccount::create(['company_id' => $parent->id, 'code' => '1100', 'name' => 'Intercompany Receivable', 'account_type' => 'asset', 'is_active' => true]);
        $parentPayable = ChartOfAccount::create(['company_id' => $parent->id, 'code' => '2100', 'name' => 'Intercompany Payable', 'account_type' => 'liability', 'is_active' => true]);
        $childAccount = ChartOfAccount::create(['company_id' => $child->id, 'code' => '4000', 'name' => 'Revenue', 'account_type' => 'income', 'is_active' => true]);
        $childReceivable = ChartOfAccount::create(['company_id' => $child->id, 'code' => '1100', 'name' => 'Intercompany Receivable', 'account_type' => 'asset', 'is_active' => true]);
        $childPayable = ChartOfAccount::create(['company_id' => $child->id, 'code' => '2100', 'name' => 'Intercompany Payable', 'account_type' => 'liability', 'is_active' => true]);
        $parentEntry = JournalEntry::create(['company_id' => $parent->id, 'entry_no' => 'CON-JE-P', 'date' => '2026-01-15', 'description' => 'Parent revenue', 'status' => 'draft']);
        $parentEntry->lines()->create(['account_id' => $parentAccount->id, 'debit' => 0, 'credit' => 100, 'currency_code' => 'USD', 'exchange_rate' => 1]);
        $parentEntry->update(['status' => 'posted', 'posted_at' => now()]);
        $childEntry = JournalEntry::create(['company_id' => $child->id, 'entry_no' => 'CON-JE-C', 'date' => '2026-01-16', 'description' => 'Subsidiary revenue', 'status' => 'draft']);
        $childEntry->lines()->create(['account_id' => $childAccount->id, 'debit' => 0, 'credit' => 200, 'currency_code' => 'USD', 'exchange_rate' => 1]);
        $childEntry->update(['status' => 'posted', 'posted_at' => now()]);

        Sanctum::actingAs($user, ['accounting:read']);
        $response = $this->getJson('/api/accounting/consolidated-trial-balance?from=2026-01-01&to=2026-01-31&reporting_currency=USD');

        $response->assertOk()->assertJsonPath('summary.period_credit', 300)->assertJsonPath('meta.reporting_currency', 'USD')->assertJsonPath('meta.intercompany_eliminations', 'not_applied')->assertJsonPath('meta.companies.0.id', $parent->id)->assertJsonPath('meta.companies.1.id', $child->id)->assertJsonPath('data.0.code', '4000')->assertJsonPath('data.0.companies.0.credit', 100)->assertJsonPath('data.0.companies.1.credit', 200);

        $statements = $this->getJson('/api/accounting/consolidated-financial-statements?from=2026-01-01&to=2026-01-31&reporting_currency=USD');
        $statements->assertOk()->assertJsonPath('summary.income', 300)->assertJsonPath('summary.net_income', 300)->assertJsonPath('data.profit_and_loss.0.amount', 300)->assertJsonPath('meta.intercompany_eliminations', 'not_applied');

        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);
        $elimination = $this->postJson('/api/accounting/consolidation-eliminations', ['external_reference' => 'CON-ELIM-1', 'consolidation_reference' => 'IC-2026-01', 'date' => '2026-01-31', 'description' => 'Eliminate intercompany balance', 'lines' => [['account_id' => $parentAccount->id, 'debit' => 25, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1], ['account_id' => $eliminationAccount->id, 'debit' => 0, 'credit' => 25, 'currency_code' => 'USD', 'exchange_rate' => 1]]]);
        $elimination->assertCreated()->assertJsonPath('data.consolidation_elimination', true)->assertJsonPath('idempotent', false);
        $this->postJson('/api/accounting/consolidation-eliminations', ['external_reference' => 'CON-ELIM-1', 'consolidation_reference' => 'IC-2026-01', 'date' => '2026-01-31', 'description' => 'Eliminate intercompany balance', 'lines' => [['account_id' => $parentAccount->id, 'debit' => 25, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1], ['account_id' => $eliminationAccount->id, 'debit' => 0, 'credit' => 25, 'currency_code' => 'USD', 'exchange_rate' => 1]]])->assertOk()->assertJsonPath('idempotent', true);
        $automaticParent = JournalEntry::create(['company_id' => $parent->id, 'entry_no' => 'CON-IC-P', 'date' => '2026-01-20', 'description' => 'Intercompany parent pair', 'status' => 'draft', 'intercompany_reference' => 'IC-AUTO-1', 'counterparty_company_id' => $child->id]);
        $automaticParent->lines()->createMany([['account_id' => $eliminationAccount->id, 'debit' => 50, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1], ['account_id' => $parentPayable->id, 'debit' => 0, 'credit' => 50, 'currency_code' => 'USD', 'exchange_rate' => 1]]);
        $automaticParent->update(['status' => 'posted', 'posted_at' => now()]);
        $automaticChild = JournalEntry::create(['company_id' => $child->id, 'entry_no' => 'CON-IC-C', 'date' => '2026-01-20', 'description' => 'Intercompany child pair', 'status' => 'draft', 'intercompany_reference' => 'IC-AUTO-1', 'counterparty_company_id' => $parent->id]);
        $automaticChild->lines()->createMany([['account_id' => $childPayable->id, 'debit' => 50, 'credit' => 0, 'currency_code' => 'USD', 'exchange_rate' => 1], ['account_id' => $childReceivable->id, 'debit' => 0, 'credit' => 50, 'currency_code' => 'USD', 'exchange_rate' => 1]]);
        $automaticChild->update(['status' => 'posted', 'posted_at' => now()]);
        $this->getJson('/api/accounting/consolidated-trial-balance?from=2026-01-01&to=2026-01-31&reporting_currency=USD')->assertJsonPath('meta.intercompany_eliminations', 'manual_and_automatic_applied')->assertJsonPath('meta.elimination_journal_count', 1)->assertJsonPath('meta.automatic_elimination_journal_count', 2);
    }
}
