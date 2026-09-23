<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Company;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Services\BankReconciliationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use InvalidArgumentException;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_account_provider_settings_are_tenant_scoped_and_hidden(): void
    {
        $company = Company::create(['name' => 'Bank Configuration Co', 'code' => 'BANK-CONFIG']);
        $otherCompany = Company::create(['name' => 'Other Bank Co', 'code' => 'OTHER-BANK']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherAccount = BankAccount::create(['company_id' => $otherCompany->id, 'name' => 'Other account', 'currency_code' => 'USD', 'provider' => 'http', 'connection_config' => ['token' => 'other-secret'], 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);

        $created = $this->postJson('/api/accounting/bank-accounts', [
            'name' => 'Configured account', 'account_no' => '001', 'currency_code' => 'usd', 'provider' => 'HTTP',
            'connection_config' => ['endpoint' => 'https://bank.example.test/{bank_account_id}', 'token' => 'tenant-secret', 'timeout' => 15],
        ])->assertCreated()->assertJsonPath('data.provider', 'http');
        $this->assertArrayNotHasKey('connection_config', $created->json('data'));
        $accountId = $created->json('data.id');
        $this->assertDatabaseHas('bank_accounts', ['id' => $accountId, 'company_id' => $company->id, 'provider' => 'http']);
        $this->assertNotSame('tenant-secret', (string) $this->app['db']->table('bank_accounts')->where('id', $accountId)->value('connection_config'));
        $listed = $this->getJson('/api/accounting/bank-accounts')->assertOk()->assertJsonPath('data.0.id', $accountId);
        $this->assertArrayNotHasKey('connection_config', $listed->json('data.0'));
        $this->patchJson('/api/accounting/bank-accounts/'.$otherAccount->id, ['name' => 'Should not change'])->assertNotFound();
        $this->patchJson('/api/accounting/bank-accounts/'.$accountId, ['connection_config' => ['endpoint' => 'https://bank.example.test/v2/{bank_account_id}', 'token' => 'rotated-secret']])
            ->assertOk()->assertJsonPath('data.provider', 'http');
        $this->postJson('/api/accounting/bank-accounts/'.$accountId.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_matched_bank_line_can_only_be_reopened_with_a_reason(): void
    {
        $company = Company::create(['name' => 'Reconciliation Co', 'code' => 'RECON-TEST']);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Operating', 'currency_code' => 'USD', 'is_active' => true]);
        $line = BankStatementLine::create([
            'company_id' => $company->id,
            'bank_account_id' => $account->id,
            'transaction_date' => '2026-09-13',
            'amount' => 125.00,
            'status' => 'matched',
            'matched_type' => 'customer_payment',
            'matched_id' => 42,
        ]);

        try {
            app(BankReconciliationService::class)->reverseMatch($line, '   ');
            $this->fail('A blank reversal reason must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame('matched', $line->fresh()->status);
        }

        $reopened = app(BankReconciliationService::class)->reverseMatch($line, 'Incorrect payment selected');

        $this->assertSame('unmatched', $reopened->status);
        $this->assertNull($reopened->matched_type);
        $this->assertNull($reopened->matched_id);
        $this->assertSame('Incorrect payment selected', $reopened->unmatch_reason);
        $this->assertNotNull($reopened->unmatched_at);
    }

    public function test_scheduled_bank_auto_match_is_conservative_and_dry_run_is_non_mutating(): void
    {
        $company = Company::create(['name' => 'Auto Match Co', 'code' => 'AUTO-MATCH']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Auto Match Customer', 'status' => 1]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Auto Match Account', 'currency_code' => 'USD', 'is_active' => true]);
        $date = now()->toDateString();
        $payment = Payment::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'paid_status' => 'unallocated',
            'approval_status' => 'approved', 'paid_amount' => 125, 'due_amount' => 0, 'total_amount' => 125,
            'payment_date' => $date, 'is_reversed' => false,
        ]);
        $ambiguousCustomer = Customer::create(['company_id' => $company->id, 'name' => 'Ambiguous Customer', 'status' => 1]);
        Payment::create(['company_id' => $company->id, 'customer_id' => $ambiguousCustomer->id, 'paid_status' => 'unallocated', 'approval_status' => 'approved', 'paid_amount' => 50, 'due_amount' => 0, 'total_amount' => 50, 'payment_date' => $date, 'is_reversed' => false]);
        Payment::create(['company_id' => $company->id, 'customer_id' => $ambiguousCustomer->id, 'paid_status' => 'unallocated', 'approval_status' => 'approved', 'paid_amount' => 50, 'due_amount' => 0, 'total_amount' => 50, 'payment_date' => $date, 'is_reversed' => false]);
        $safeLine = BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => $date, 'amount' => 125, 'status' => 'unmatched']);
        $ambiguousLine = BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => $date, 'amount' => 50, 'status' => 'unmatched']);

        $this->artisan('erp:accounting:auto-match-bank-statements', ['--company' => $company->id, '--from' => $date, '--to' => $date, '--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $safeLine->id, 'status' => 'unmatched']);
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $ambiguousLine->id, 'status' => 'unmatched']);

        $this->artisan('erp:accounting:auto-match-bank-statements', ['--company' => $company->id, '--from' => $date, '--to' => $date])->assertExitCode(0);
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $safeLine->id, 'status' => 'matched', 'matched_type' => 'customer_payment', 'matched_id' => $payment->id]);
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $ambiguousLine->id, 'status' => 'unmatched']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_statement_line.auto_matched', 'auditable_id' => $safeLine->id]);
    }

    public function test_scheduled_bank_sync_imports_active_accounts_and_retains_failures(): void
    {
        $company = Company::create(['name' => 'Scheduled Bank Co', 'code' => 'SCHEDULED-BANK']);
        $account = BankAccount::create([
            'company_id' => $company->id,
            'name' => 'Scheduled account',
            'currency_code' => 'USD',
            'provider' => 'http',
            'connection_config' => ['endpoint' => 'https://bank.example.test/{bank_account_id}', 'token' => 'scheduled-secret'],
            'is_active' => true,
        ]);
        $failedAccount = BankAccount::create([
            'company_id' => $company->id,
            'name' => 'Unavailable account',
            'currency_code' => 'USD',
            'provider' => 'http',
            'connection_config' => ['endpoint' => 'https://bank.example.test/fail/{bank_account_id}'],
            'is_active' => true,
        ]);
        Http::fake(function ($request) use ($account, $failedAccount) {
            if (str_contains($request->url(), '/fail/'.$failedAccount->id)) return Http::response([], 503);
            if (str_contains($request->url(), '/'.$account->id)) return Http::response(['lines' => [[
                'account_id' => $account->id, 'date' => '2026-09-20', 'amount' => 250, 'transaction_id' => 'SCHEDULED-1',
            ]]], 200);
            return Http::response([], 404);
        });

        $this->artisan('erp:accounting:sync-bank-statements', ['--company' => $company->id, '--from' => '2026-09-20', '--to' => '2026-09-20'])
            ->assertExitCode(1);

        $this->assertSame('success', $account->fresh()->last_sync_status, (string) $account->fresh()->last_sync_error);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/'.$account->id));
        $this->assertDatabaseHas('bank_statement_lines', ['external_reference' => 'SCHEDULED-1', 'import_batch_id' => 1]);
        $this->assertDatabaseHas('bank_statement_import_batches', ['source' => 'scheduled', 'created_lines' => 1]);
        $this->assertDatabaseHas('bank_accounts', ['id' => $account->id, 'last_sync_status' => 'success']);
        $this->assertDatabaseHas('bank_accounts', ['id' => $failedAccount->id, 'last_sync_status' => 'failed']);
        $this->assertStringContainsString('503', (string) $failedAccount->fresh()->last_sync_error);

        $this->artisan('erp:accounting:sync-bank-statements', ['--company' => $company->id, '--from' => '2026-09-20', '--to' => '2026-09-20'])
            ->assertExitCode(1);
        $this->assertDatabaseCount('bank_statement_lines', 1);
    }

    public function test_generic_bank_provider_payload_is_normalized_and_idempotent(): void
    {
        $company = Company::create(['name' => 'Bank Feed Co', 'code' => 'BANK-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Feed Account', 'currency_code' => 'USD', 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);

        $payload = ['provider' => 'GENERIC', 'payload' => ['account_id' => $account->id, 'date' => '2026-09-15', 'amount' => '-125.50', 'transaction_id' => 'BANK-FEED-1', 'narration' => 'Supplier payment']];
        $created = $this->postJson('/api/accounting/bank-reconciliation/lines', $payload);
        $created->assertCreated()->assertJsonPath('data.provider', 'generic')->assertJsonPath('data.external_reference', 'BANK-FEED-1');

        $duplicate = $this->postJson('/api/accounting/bank-reconciliation/lines', $payload);
        $duplicate->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $created->json('data.id'));
        $this->getJson('/api/accounting/bank-reconciliation/lines?provider=generic')->assertOk()->assertJsonPath('data.0.external_reference', 'BANK-FEED-1');
    }

    public function test_bank_provider_bulk_import_is_atomic_and_reports_duplicates(): void
    {
        $company = Company::create(['name' => 'Bulk Bank Feed Co', 'code' => 'BULK-BANK-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Bulk Feed Account', 'currency_code' => 'USD', 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);
        $lines = ['lines' => [
            ['payload' => ['account_id' => $account->id, 'date' => '2026-09-15', 'amount' => 100, 'transaction_id' => 'BULK-1']],
            ['payload' => ['account_id' => $account->id, 'date' => '2026-09-16', 'amount' => -50, 'transaction_id' => 'BULK-2']],
        ]];

        $created = $this->postJson('/api/accounting/bank-reconciliation/lines/bulk', $lines);
        $created->assertCreated()->assertJsonPath('created', 2)->assertJsonPath('duplicates', 0);
        $firstBatchId = $created->json('batch_id');
        $duplicate = $this->postJson('/api/accounting/bank-reconciliation/lines/bulk', $lines);
        $duplicate->assertCreated()->assertJsonPath('created', 0)->assertJsonPath('duplicates', 2);
        $secondBatchId = $duplicate->json('batch_id');
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $this->assertDatabaseHas('bank_statement_import_batches', ['id' => $firstBatchId, 'total_lines' => 2, 'created_lines' => 2, 'duplicate_lines' => 0, 'status' => 'completed']);
        $this->getJson('/api/accounting/bank-reconciliation/import-batches?provider=generic')->assertOk()->assertJsonPath('data.0.id', $secondBatchId)->assertJsonPath('data.0.duplicate_lines', 2);
    }

    public function test_failed_bank_bulk_import_rolls_back_lines_and_batch(): void
    {
        $company = Company::create(['name' => 'Atomic Bank Feed Co', 'code' => 'ATOMIC-BANK-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Atomic Feed Account', 'currency_code' => 'USD', 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:write']);

        $response = $this->postJson('/api/accounting/bank-reconciliation/lines/bulk', ['lines' => [
            ['payload' => ['account_id' => $account->id, 'date' => '2026-09-15', 'amount' => 100, 'transaction_id' => 'ATOMIC-1']],
            ['payload' => ['account_id' => $account->id, 'date' => 'not-a-date', 'amount' => -50, 'transaction_id' => 'ATOMIC-2']],
        ]]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('bank_statement_lines', 0);
        $this->assertDatabaseCount('bank_statement_import_batches', 0);
    }

    public function test_unmatched_bank_line_can_be_settled_to_a_gl_account_idempotently(): void
    {
        $company = Company::create(['name' => 'Bank Settlement Co', 'code' => 'BANK-SETTLE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $bankGl = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1020', 'name' => 'Operating bank GL', 'account_type' => 'asset', 'is_active' => true]);
        $feeGl = ChartOfAccount::create(['company_id' => $company->id, 'code' => '6250', 'name' => 'Bank charges', 'account_type' => 'expense', 'is_active' => true]);
        FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account = BankAccount::create(['company_id' => $company->id, 'name' => 'Operating', 'currency_code' => 'USD', 'gl_account_id' => $bankGl->id, 'is_active' => true]);
        $line = BankStatementLine::create(['company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_date' => '2026-09-17', 'amount' => -25, 'reference' => 'FEE-1', 'status' => 'unmatched']);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);

        $settled = $this->postJson('/api/accounting/bank-reconciliation/lines/'.$line->id.'/settle', ['account_id' => $feeGl->id, 'reason' => 'Monthly bank service fee'])
            ->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('data.status', 'matched')->assertJsonPath('data.matched_type', 'journal_entry');
        $journalId = $settled->json('data.settlement_journal_id');
        $this->assertNotNull($journalId);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $feeGl->id, 'debit' => 25]);
        $this->postJson('/api/accounting/bank-reconciliation/lines/'.$line->id.'/settle', ['account_id' => $feeGl->id, 'reason' => 'Replay'])
            ->assertOk()->assertJsonPath('data.settlement_journal_id', $journalId);
        $this->postJson('/api/accounting/bank-reconciliation/lines/'.$line->id.'/match/reverse', ['reason' => 'Needs reversal'])
            ->assertStatus(422);
        $reversed = $this->postJson('/api/accounting/bank-reconciliation/lines/'.$line->id.'/settle/reverse', ['reason' => 'Fee was disputed'])
            ->assertOk()->assertJsonPath('status', 'unmatched')->assertJsonPath('data.status', 'unmatched');
        $this->assertNotNull($reversed->json('data.settlement_reversal_journal_id'));
        $this->postJson('/api/accounting/bank-reconciliation/lines/'.$line->id.'/settle/reverse', ['reason' => 'Replay reversal'])
            ->assertStatus(422);
    }
}
