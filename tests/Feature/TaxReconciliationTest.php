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

        $filing = $this->postJson('/api/accounting/tax-filings', ['from' => '2026-09-01', 'to' => '2026-09-30', 'jurisdiction' => 'IN', 'external_reference' => 'GST-SEP-2026'])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('data.net_tax', '10.000000')->assertJsonPath('data.return_type', 'indirect_tax');
        $filingId = $filing->json('data.id');
        $this->assertNotEmpty($filing->json('data.snapshot_hash'));
        $this->assertNotEmpty($filing->json('data.snapshot_payload.report.summary'));
        $this->getJson('/api/accounting/tax-filings/'.$filingId.'/verify')->assertOk()->assertJsonPath('status', 'verified')->assertJsonPath('data.verified', true);
        $this->postJson('/api/accounting/tax-filings/'.$filingId.'/submit', ['filing_reference' => 'PORTAL-123'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.filing_reference', 'PORTAL-123');
        $this->postJson('/api/accounting/tax-filings/'.$filingId.'/decision', ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson('/api/accounting/tax-filings', ['from' => '2026-09-01', 'to' => '2026-09-30', 'jurisdiction' => 'IN', 'external_reference' => 'GST-SEP-2026'])
            ->assertOk()->assertJsonPath('status', 'existing')->assertJsonPath('data.id', $filingId);
    }
}
