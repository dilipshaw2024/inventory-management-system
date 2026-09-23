<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Category;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaxReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_reconciliation_compares_document_tax_to_posted_tax_control_balance(): void
    {
        $company = Company::create(['name' => 'Tax Control Co', 'code' => 'TAX-CONTROL']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $taxAccount = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2200', 'name' => 'Tax payable', 'account_type' => 'liability', 'is_control_account' => true, 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'tax_payable', 'account_id' => $taxAccount->id]);
        $cashAccount = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1010', 'name' => 'Tax bank', 'account_type' => 'asset', 'is_active' => true]);
        AccountMapping::create(['company_id' => $company->id, 'mapping_key' => 'cash_bank', 'account_id' => $cashAccount->id]);
        FiscalYear::create(['company_id' => $company->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Tax supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Tax category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Taxable item', 'quantity' => 0, 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'INV-TAX-1', 'date' => '2026-09-15', 'status' => 1, 'tax_jurisdiction' => 'IN', 'subtotal_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110]);
        $invoice->invoice_details()->create(['date' => '2026-09-15', 'product_id' => $product->id, 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'tax_rate' => 10, 'tax_amount' => 10, 'status' => 1]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'JE-TAX-1', 'date' => '2026-09-15', 'description' => 'Tax control posting', 'status' => 'draft']);
        $entry->lines()->create(['account_id' => $taxAccount->id, 'debit' => 0, 'credit' => 8, 'currency_code' => 'USD', 'exchange_rate' => 1]);
        $entry->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);

        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);
        $response = $this->getJson('/api/accounting/tax-reconciliation?from=2026-09-01&to=2026-09-30&tolerance=0.01');

        $response->assertOk()
            ->assertJsonPath('summary.expected_net_tax', 10)
            ->assertJsonPath('summary.posted_tax_control_net', 8)
            ->assertJsonPath('summary.variance', 2)
            ->assertJsonPath('summary.status', 'variance')
            ->assertJsonPath('summary.mapped_tax_accounts.0', $taxAccount->id);

        $settled = $this->postJson('/api/accounting/tax-settlements', ['from' => '2026-09-01', 'to' => '2026-09-30', 'paid_at' => '2026-09-30', 'payment_reference' => 'TAX-BANK-1'])
            ->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('data.net_tax', '10.000000');
        $journalId = $settled->json('data.journal_entry_id');
        $this->assertNotNull($journalId);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_id' => $taxAccount->id, 'debit' => 10]);
        $this->postJson('/api/accounting/tax-settlements', ['from' => '2026-09-01', 'to' => '2026-09-30', 'paid_at' => '2026-10-01', 'payment_reference' => 'REPLAY'])
            ->assertOk()->assertJsonPath('data.journal_entry_id', $journalId)->assertJsonPath('data.payment_reference', 'TAX-BANK-1');
        $reversed = $this->postJson('/api/accounting/tax-settlements/'.$settled->json('data.id').'/reverse', ['reason' => 'Corrected remittance period'])
            ->assertOk()->assertJsonPath('status', 'reversed')->assertJsonPath('data.status', 'reversed');
        $reversalJournalId = $reversed->json('data.reversal_journal_entry_id');
        $this->assertNotNull($reversalJournalId);
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'status' => 'reversed']);
        $this->assertDatabaseHas('journal_entries', ['id' => $reversalJournalId, 'reversal_of_id' => $journalId, 'status' => 'posted']);
        $this->postJson('/api/accounting/tax-settlements/'.$settled->json('data.id').'/reverse', ['reason' => 'Duplicate reversal'])
            ->assertStatus(422);
        $this->postJson('/api/accounting/tax-settlements', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'paid_at' => '2026-10-02',
            'payment_reference' => 'TAX-BANK-CORRECTION', 'external_reference' => 'TAX-CORRECTION-1',
        ])->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('data.settlement_no', 'TAX-SET-2026-09-01-2026-09-30-R2');
        $this->postJson('/api/accounting/tax-settlements', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'paid_at' => '2026-10-03',
            'payment_reference' => 'REPLAY-CORRECTION', 'external_reference' => 'TAX-CORRECTION-1',
        ])->assertOk()->assertJsonPath('data.payment_reference', 'TAX-BANK-CORRECTION');
        $this->postJson('/api/accounting/tax-settlements', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'paid_at' => '2026-10-04',
        ])->assertStatus(422);
        $this->getJson('/api/accounting/tax-settlements?status=reversed&cursor_mode=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.feed', 'accounting.tax-settlements')->assertJsonPath('data.0.id', $settled->json('data.id'))
            ->assertJsonPath('data.0.reversal_journal_entry_id', $reversalJournalId);
        $this->getJson('/api/accounting/tax-settlements?status=posted&jurisdiction=IN')
            ->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/accounting/journals?include_reversed=1&cursor_mode=1')
            ->assertOk()->assertJsonPath('meta.feed', 'accounting.journals')
            ->assertJsonFragment(['id' => $journalId, 'status' => 'reversed'])
            ->assertJsonFragment(['id' => $reversalJournalId, 'status' => 'posted']);

        $filing = $this->postJson('/api/accounting/tax-filings', ['from' => '2026-09-01', 'to' => '2026-09-30', 'jurisdiction' => 'IN', 'external_reference' => 'GST-SEP-2026'])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('data.net_tax', '10.000000')->assertJsonPath('data.return_type', 'indirect_tax');
        $filingId = $filing->json('data.id');
        $this->assertNotEmpty($filing->json('data.snapshot_hash'));
        $this->assertNotEmpty($filing->json('data.snapshot_payload.report.summary'));
        $this->getJson('/api/accounting/tax-filings/'.$filingId.'/verify')->assertOk()->assertJsonPath('status', 'verified')->assertJsonPath('data.verified', true);
        $this->getJson('/api/accounting/tax-filings/'.$filingId.'/export?format=json')->assertOk()->assertJsonPath('status', 'exported')->assertJsonPath('data.integrity.verified', true)->assertJsonPath('data.filing.snapshot_hash', $filing->json('data.snapshot_hash'));
        $this->get('/api/accounting/tax-filings/'.$filingId.'/export?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('content-disposition', 'attachment; filename='.$filing->json('data.filing_no').'.csv');
        $this->postJson('/api/accounting/tax-filings/'.$filingId.'/submit', ['filing_reference' => 'PORTAL-123'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.filing_reference', 'PORTAL-123');
        $this->postJson('/api/accounting/tax-filings/'.$filingId.'/decision', ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson('/api/accounting/tax-filings', ['from' => '2026-09-01', 'to' => '2026-09-30', 'jurisdiction' => 'IN', 'external_reference' => 'GST-SEP-2026'])
            ->assertOk()->assertJsonPath('status', 'existing')->assertJsonPath('data.id', $filingId);
    }

    public function test_tax_filing_provider_settings_are_encrypted_and_gateway_submission_is_idempotency_bound(): void
    {
        $company = Company::create(['name' => 'Tax Gateway Co', 'code' => 'TAX-GATEWAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write', 'integration:write']);
        $configured = $this->postJson('/api/accounting/tax-filing-providers', ['provider' => 'HTTP', 'connection_config' => ['endpoint' => 'https://filing-gateway.example.test/submit', 'token' => 'filing-secret']])
            ->assertCreated()->assertJsonPath('data.provider', 'http');
        $this->assertArrayNotHasKey('connection_config', $configured->json('data'));
        $settingId = $configured->json('data.id');
        $this->assertNotSame('filing-secret', (string) $this->app['db']->table('tax_filing_provider_settings')->where('id', $settingId)->value('connection_config'));
        $filing = $this->postJson('/api/accounting/tax-filings', ['from' => '2026-09-01', 'to' => '2026-09-30', 'jurisdiction' => 'IN', 'external_reference' => 'TAX-GATEWAY-SEP'])->assertCreated();
        Http::fake(['https://filing-gateway.example.test/*' => Http::sequence()
            ->push(['status' => 'accepted', 'reference' => 'GATEWAY-FILING-1'], 200)
            ->push(['message' => 'gateway unavailable'], 503)
            ->push(['message' => 'gateway unavailable'], 503)
            ->push(['message' => 'gateway unavailable'], 503)
            ->push(['status' => 'accepted', 'reference' => 'GATEWAY-FILING-RETRY'], 200)]);
        $this->postJson('/api/accounting/tax-filings/'.$filing->json('data.id').'/submit', ['provider' => 'http'])
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.filing_reference', 'GATEWAY-FILING-1')->assertJsonPath('data.submission_provider', 'http');
        $this->postJson('/api/accounting/tax-filings/'.$filing->json('data.id').'/submit', ['provider' => 'http'])->assertStatus(422);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer filing-secret') && $request->hasHeader('Idempotency-Key', 'ERP-TAX-FILING-'.$filing->json('data.id')));
        $this->getJson('/api/accounting/tax-filing-providers')->assertOk()->assertJsonPath('data.0.id', $settingId);
        $this->postJson('/api/accounting/tax-filing-providers/'.$settingId.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);

        $this->postJson('/api/accounting/tax-filing-providers', ['provider' => 'http', 'connection_config' => ['endpoint' => 'https://filing-gateway.example.test/submit', 'token' => 'filing-secret']])->assertCreated();
        $retryableFiling = $this->postJson('/api/accounting/tax-filings', ['from' => '2026-10-01', 'to' => '2026-10-31', 'jurisdiction' => 'IN', 'external_reference' => 'TAX-GATEWAY-OCT'])->assertCreated();
        $this->postJson('/api/accounting/tax-filings/'.$retryableFiling->json('data.id').'/submit', ['provider' => 'http'])->assertStatus(422);
        $this->assertStringContainsString('503', (string) $this->app['db']->table('tax_filings')->where('id', $retryableFiling->json('data.id'))->value('provider_error'));
        $this->assertSame('draft', $this->app['db']->table('tax_filings')->where('id', $retryableFiling->json('data.id'))->value('status'));
        $this->getJson('/api/accounting/tax-filings?status=draft&submission_provider=http&has_provider_error=1')->assertOk()->assertJsonFragment(['id' => $retryableFiling->json('data.id')]);
        $this->artisan('erp:accounting:retry-tax-filings', ['--company' => $company->id])->assertExitCode(0);
        $this->assertSame('accepted', $this->app['db']->table('tax_filings')->where('id', $retryableFiling->json('data.id'))->value('status'));
        $this->assertSame('GATEWAY-FILING-RETRY', $this->app['db']->table('tax_filings')->where('id', $retryableFiling->json('data.id'))->value('filing_reference'));
        $this->getJson('/api/accounting/tax-filings?status=draft&has_provider_error=1')->assertOk()->assertJsonPath('data', []);
    }
}
