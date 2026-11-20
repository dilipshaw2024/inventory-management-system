<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankStatementReconciliationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_can_create_close_and_reopen_statement_period(): void
    {
        $company = Company::create(['name' => 'Browser Statement Co', 'code' => 'BROWSER-STATEMENT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'accounting.manage', 'name' => 'Accounting management', 'module' => 'accounting']);
        $role = Role::create(['name' => 'Browser accountant', 'code' => 'browser-accountant']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Browser Main', 'currency_code' => 'USD', 'is_active' => true]);
        BankStatementLine::create([
            'company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-09-18',
            'amount' => 100, 'status' => 'matched', 'reference' => 'BROWSER-RECEIPT',
        ]);

        $this->actingAs($user)->get('/erp/accounting/bank-reconciliation')->assertOk()->assertSee('Statement reconciliation periods');
        $this->actingAs($user)->post('/erp/accounting/bank-reconciliation/statements', [
            'bank_account_id' => $account->id, 'statement_date' => '2026-09-18', 'opening_balance' => 0, 'closing_balance' => 100,
        ])->assertRedirect();
        $statement = \App\Models\BankReconciliation::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($user)->post('/erp/accounting/bank-reconciliation/statements/'.$statement->id.'/close', ['tolerance' => 0.01])->assertRedirect();
        $this->assertDatabaseHas('bank_reconciliations', ['id' => $statement->id, 'status' => 'closed']);
        $this->actingAs($user)->post('/erp/accounting/bank-reconciliation/statements/'.$statement->id.'/reopen', ['reason' => 'Corrected bank statement received.'])->assertRedirect();
        $this->assertDatabaseHas('bank_reconciliations', ['id' => $statement->id, 'status' => 'draft']);

        $bankGl = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1020', 'name' => 'Browser bank GL', 'account_type' => 'asset', 'is_active' => true]);
        $feeGl = ChartOfAccount::create(['company_id' => $company->id, 'code' => '6250', 'name' => 'Browser bank fees', 'account_type' => 'expense', 'is_active' => true]);
        FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account->update(['gl_account_id' => $bankGl->id]);
        $line = BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-09-19', 'amount' => -25, 'status' => 'unmatched', 'reference' => 'BROWSER-FEE']);
        $this->actingAs($user)->post('/erp/accounting/bank-reconciliation/lines/'.$line->id.'/settle', ['account_id' => $feeGl->id, 'reason' => 'Browser bank fee settlement'])->assertRedirect();
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $line->id, 'status' => 'matched', 'matched_type' => 'journal_entry']);
        $this->actingAs($user)->post('/erp/accounting/bank-reconciliation/lines/'.$line->id.'/settle/reverse', ['reason' => 'Browser bank fee correction'])->assertRedirect();
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $line->id, 'status' => 'unmatched']);
    }

    public function test_statement_can_be_created_closed_and_reopened_with_audit_safe_gate(): void
    {
        $company = Company::create(['name' => 'Statement Close Co', 'code' => 'STATEMENT-CLOSE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Main Account', 'currency_code' => 'USD', 'is_active' => true]);
        BankStatementLine::create([
            'company_id' => $company->id,
            'bank_account_id' => $account->id,
            'transaction_date' => '2026-09-18',
            'amount' => 100,
            'status' => 'matched',
            'reference' => 'RECEIPT-1',
        ]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);

        $created = $this->postJson('/api/accounting/bank-reconciliation/statements', [
            'bank_account_id' => $account->id,
            'statement_date' => '2026-09-18',
            'opening_balance' => 0,
            'closing_balance' => 100,
        ]);
        $created->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.book_balance', '100.000000')->assertJsonPath('data.difference', '0.000000');
        $id = $created->json('data.id');

        $duplicate = $this->postJson('/api/accounting/bank-reconciliation/statements', [
            'bank_account_id' => $account->id,
            'statement_date' => '2026-09-18',
            'opening_balance' => 0,
            'closing_balance' => 100,
        ]);
        $duplicate->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $id);

        $this->postJson('/api/accounting/bank-reconciliation/statements/'.$id.'/close')
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/accounting/bank-reconciliation/statements?status=closed')
            ->assertOk()->assertJsonPath('data.0.id', $id);

        $this->postJson('/api/accounting/bank-reconciliation/statements/'.$id.'/reopen', ['reason' => 'Bank supplied a corrected statement.'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_statement_close_rejects_unmatched_lines(): void
    {
        $company = Company::create(['name' => 'Statement Gate Co', 'code' => 'STATEMENT-GATE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Gate Account', 'currency_code' => 'USD', 'is_active' => true]);
        BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-09-18', 'amount' => -25, 'status' => 'unmatched']);
        Sanctum::actingAs($user, ['accounting:write']);

        $id = $this->postJson('/api/accounting/bank-reconciliation/statements', ['bank_account_id' => $account->id, 'statement_date' => '2026-09-18', 'opening_balance' => 0, 'closing_balance' => -25])->json('data.id');
        $this->postJson('/api/accounting/bank-reconciliation/statements/'.$id.'/close')
            ->assertStatus(422)->assertJsonPath('message', 'All statement lines must be matched, settled, or ignored before closing.');
    }
}
