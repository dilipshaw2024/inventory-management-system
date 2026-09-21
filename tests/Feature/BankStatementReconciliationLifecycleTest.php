<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankStatementReconciliationLifecycleTest extends TestCase
{
    use RefreshDatabase;

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
