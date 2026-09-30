<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\ChartOfAccount;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosSessionIntegrationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_pos_register_session_and_cash_reconciliation_are_idempotent_and_company_scoped(): void
    {
        $company = Company::create(['name' => 'POS Company', 'code' => 'POS-COMPANY', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-BRANCH']);
        $store = Store::create(['branch_id' => $branch->id, 'name' => 'Downtown', 'code' => 'POS-STORE', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $register = $this->postJson('/api/pos/registers', [
            'store_id' => $store->id,
            'code' => 'REG-01',
            'name' => 'Front Counter',
            'external_reference' => 'POS-REG-1',
        ])->assertCreated()->assertJsonPath('status', 'created')->json('data');

        $this->postJson('/api/pos/registers', [
            'store_id' => $store->id,
            'code' => 'DIFFERENT',
            'name' => 'Replay',
            'external_reference' => 'POS-REG-1',
        ])->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $register['id']);

        $session = $this->postJson('/api/pos/registers/'.$register['id'].'/sessions', [
            'opening_cash' => 100,
            'external_reference' => 'POS-SESSION-1',
        ])->assertCreated()->assertJsonPath('status', 'opened')->json('data');

        $this->postJson('/api/pos/sessions/'.$session['id'].'/cash-movements', [
            'type' => 'cash_in', 'amount' => 20, 'external_reference' => 'CASH-IN-1',
        ])->assertCreated();

        $this->postJson('/api/pos/sessions/'.$session['id'].'/cash-movements', [
            'type' => 'cash_out', 'amount' => 5, 'external_reference' => 'CASH-OUT-1',
        ])->assertCreated();

        $invoice = Invoice::create([
            'company_id' => $company->id, 'store_id' => $store->id, 'invoice_no' => 'POS-INV-1',
            'date' => '2026-09-28', 'status' => 1, 'total_amount' => 50,
        ]);
        Payment::create([
            'company_id' => $company->id, 'invoice_id' => $invoice->id, 'pos_session_id' => $session['id'],
            'method' => 'cash', 'paid_status' => 'full_paid', 'approval_status' => 'approved', 'paid_amount' => 50,
            'due_amount' => 0, 'total_amount' => 50, 'payment_date' => '2026-09-28',
            'currency_code' => 'USD', 'exchange_rate' => 1, 'base_amount' => 50,
        ]);
        $cardInvoice = Invoice::create([
            'company_id' => $company->id, 'store_id' => $store->id, 'invoice_no' => 'POS-INV-2',
            'date' => '2026-09-28', 'status' => 1, 'total_amount' => 25,
        ]);
        Payment::create([
            'company_id' => $company->id, 'invoice_id' => $cardInvoice->id, 'pos_session_id' => $session['id'],
            'method' => 'card', 'paid_status' => 'full_paid', 'approval_status' => 'approved', 'paid_amount' => 25,
            'due_amount' => 0, 'total_amount' => 25, 'payment_date' => '2026-09-28',
            'currency_code' => 'USD', 'exchange_rate' => 1, 'base_amount' => 25,
        ]);

        $this->getJson('/api/pos/sessions?status=open')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sales_cash', 50)
            ->assertJsonPath('data.0.tender_totals.cash', 50)
            ->assertJsonPath('data.0.tender_totals.card', 25)
            ->assertJsonPath('data.0.expected_cash', 165);

        $this->postJson('/api/pos/sessions/'.$session['id'].'/close', [
            'closing_cash' => 165,
            'closing_note' => 'Balanced at close',
        ])->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('data.expected_cash', 165)
            ->assertJsonPath('data.tender_totals.card', 25)
            ->assertJsonPath('data.variance', 0)
            ->assertJsonPath('data.variance_accounting_status', 'not_required');

        $this->postJson('/api/pos/sessions/'.$session['id'].'/cash-movements', [
            'type' => 'cash_in', 'amount' => 1,
        ])->assertStatus(422);
    }

    public function test_pos_session_summary_is_read_only_and_company_scoped(): void
    {
        $company = Company::create(['name' => 'POS Summary Company', 'code' => 'POS-SUMMARY', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-SUMMARY-BRANCH']);
        $store = Store::create(['branch_id' => $branch->id, 'name' => 'Summary Store', 'code' => 'POS-SUMMARY-STORE', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $register = $this->postJson('/api/pos/registers', [
            'store_id' => $store->id, 'code' => 'REG-SUMMARY', 'name' => 'Summary Counter',
        ])->assertCreated()->json('data');
        $session = $this->postJson('/api/pos/registers/'.$register['id'].'/sessions', [
            'opening_cash' => 100,
        ])->assertCreated()->json('data');

        $invoice = Invoice::create([
            'company_id' => $company->id, 'store_id' => $store->id, 'invoice_no' => 'POS-SUMMARY-INV',
            'date' => '2026-09-28', 'status' => 1, 'total_amount' => 75,
        ]);
        Payment::create([
            'company_id' => $company->id, 'invoice_id' => $invoice->id, 'pos_session_id' => $session['id'],
            'method' => 'card', 'paid_status' => 'full_paid', 'approval_status' => 'approved', 'paid_amount' => 75,
            'due_amount' => 0, 'total_amount' => 75, 'payment_date' => '2026-09-28',
            'currency_code' => 'USD', 'exchange_rate' => 1, 'base_amount' => 75,
        ]);
        $this->postJson('/api/pos/sessions/'.$session['id'].'/cash-movements', [
            'type' => 'cash_out', 'amount' => 10,
        ])->assertCreated();

        $this->getJson('/api/pos/sessions/'.$session['id'].'/summary')
            ->assertOk()
            ->assertJsonPath('data.meta.read_only', true)
            ->assertJsonPath('data.sales.invoice_count', 1)
            ->assertJsonPath('data.sales.payment_total', 75)
            ->assertJsonPath('data.sales.tender_totals.card', 75)
            ->assertJsonPath('data.cash_movements.by_type.cash_out', 10)
            ->assertJsonPath('data.cash_reconciliation.expected_cash', 90);

        $this->assertDatabaseHas('pos_sessions', ['id' => $session['id'], 'status' => 'open']);
    }

    public function test_store_assigned_user_is_limited_to_assigned_pos_store(): void
    {
        $company = Company::create(['name' => 'POS Store Scope Company', 'code' => 'POS-STORE-SCOPE', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-SCOPE-BRANCH']);
        $assignedStore = Store::create(['branch_id' => $branch->id, 'name' => 'Assigned Store', 'code' => 'POS-SCOPE-A', 'is_active' => true]);
        $otherStore = Store::create(['branch_id' => $branch->id, 'name' => 'Other Store', 'code' => 'POS-SCOPE-B', 'is_active' => true]);
        $assignedRegister = \App\Models\PosRegister::create(['company_id' => $company->id, 'store_id' => $assignedStore->id, 'code' => 'REG-SCOPE-A', 'name' => 'Assigned Register', 'is_active' => true]);
        \App\Models\PosRegister::create(['company_id' => $company->id, 'store_id' => $otherStore->id, 'code' => 'REG-SCOPE-B', 'name' => 'Other Register', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'store_id' => $assignedStore->id]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $this->getJson('/api/pos/registers')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $assignedRegister->id);

        $this->postJson('/api/pos/registers', [
            'store_id' => $otherStore->id, 'code' => 'REG-SCOPE-C', 'name' => 'Forbidden Register',
        ])->assertForbidden();
        $this->postJson('/api/pos/registers/999999/sessions', ['opening_cash' => 10])->assertStatus(422);
    }

    public function test_store_pos_settings_are_validated_and_persisted_through_integration_api(): void
    {
        $company = Company::create(['name' => 'POS Settings Company', 'code' => 'POS-SETTINGS', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-SETTINGS-BRANCH']);
        $store = Store::create(['branch_id' => $branch->id, 'name' => 'Configured Store', 'code' => 'POS-SETTINGS-STORE', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['integration:write']);

        $this->patchJson('/api/integration/organization/stores/'.$store->id, [
            'pos_settings' => [
                'allowed_tenders' => ['card', 'cash'],
                'receipt_footer' => 'Thank you',
                'require_customer' => true,
                'cash_variance_tolerance' => 1.25,
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('data.pos_settings.allowed_tenders.0', 'card')
            ->assertJsonPath('data.pos_settings.allowed_tenders.1', 'cash')
            ->assertJsonPath('data.pos_settings.require_customer', true)
            ->assertJsonPath('data.pos_settings.cash_variance_tolerance', 1.25);

        $this->patchJson('/api/integration/organization/stores/'.$store->id, [
            'pos_settings' => ['allowed_tenders' => ['crypto']],
        ])->assertStatus(422);
    }

    public function test_pos_variance_posts_cash_over_short_journal_when_mapped(): void
    {
        $company = Company::create(['name' => 'POS Accounting Company', 'code' => 'POS-ACCOUNTING', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-ACC-BRANCH']);
        $store = Store::create(['branch_id' => $branch->id, 'name' => 'Downtown', 'code' => 'POS-ACC-STORE', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open']);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1010', 'name' => 'POS Cash', 'account_type' => 'asset', 'is_active' => true]);
        $variance = ChartOfAccount::create(['company_id' => $company->id, 'code' => '4890', 'name' => 'Cash Over Short', 'account_type' => 'expense', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cash', 'account_id' => $cash->id]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cash_over_short', 'account_id' => $variance->id]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $register = $this->postJson('/api/pos/registers', [
            'store_id' => $store->id, 'code' => 'REG-ACC', 'name' => 'Accounting Counter',
        ])->assertCreated()->json('data');
        $session = $this->postJson('/api/pos/registers/'.$register['id'].'/sessions', [
            'opening_cash' => 100,
        ])->assertCreated()->json('data');

        $closed = $this->postJson('/api/pos/sessions/'.$session['id'].'/close', [
            'closing_cash' => 101, 'closing_note' => 'One-dollar overage',
        ])->assertOk()
            ->assertJsonPath('data.variance', 1)
            ->assertJsonPath('data.variance_accounting_status', 'posted')
            ->json('data');

        $this->assertNotNull($closed['variance_journal_id']);
        $journal = JournalEntry::with('lines')->findOrFail($closed['variance_journal_id']);
        $this->assertSame('posted', $journal->status);
        $this->assertEquals(1.0, (float) $journal->lines->where('account_id', $cash->id)->sum('debit'));
        $this->assertEquals(1.0, (float) $journal->lines->where('account_id', $variance->id)->sum('credit'));
    }

    public function test_store_cash_variance_tolerance_suppresses_small_variance_accounting(): void
    {
        $company = Company::create(['name' => 'POS Tolerance Company', 'code' => 'POS-TOLERANCE', 'base_currency' => 'USD']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'POS-TOLERANCE-BRANCH']);
        $store = Store::create([
            'branch_id' => $branch->id, 'name' => 'Tolerance Store', 'code' => 'POS-TOLERANCE-STORE', 'is_active' => true,
            'pos_settings' => ['cash_variance_tolerance' => 2],
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $register = $this->postJson('/api/pos/registers', [
            'store_id' => $store->id, 'code' => 'REG-TOLERANCE', 'name' => 'Tolerance Counter',
        ])->assertCreated()->json('data');
        $session = $this->postJson('/api/pos/registers/'.$register['id'].'/sessions', [
            'opening_cash' => 100,
        ])->assertCreated()->json('data');

        $closed = $this->postJson('/api/pos/sessions/'.$session['id'].'/close', [
            'closing_cash' => 101,
        ])->assertOk()
            ->assertJsonPath('data.variance', 1)
            ->assertJsonPath('data.cash_variance_tolerance', 2)
            ->assertJsonPath('data.variance_accounting_status', 'not_required')
            ->json('data');

        $this->assertNull($closed['variance_journal_id']);
        $this->assertSame('Cash variance is within the configured store tolerance.', $closed['variance_accounting_message']);
    }

}
