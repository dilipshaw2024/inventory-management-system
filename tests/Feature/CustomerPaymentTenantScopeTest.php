<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\SupplierPaymentAllocation;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Services\CustomerPaymentAllocationService;
use App\Services\SupplierPaymentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPaymentTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_customer_payment_is_persisted_with_token_company_scope(): void
    {
        $company = Company::create(['name' => 'AR Tenant Co', 'code' => 'AR-TENANT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Tenant Customer', 'status' => 1]);
        $token = $user->createToken('accounting-test', ['accounting:write'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/accounting/customer-payments', [
            'customer_id' => $customer->id, 'paid_amount' => 125.50, 'currency_code' => 'USD',
        ]);

        $response->assertCreated()->assertJsonPath('data.company_id', $company->id);
        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'company_id' => $company->id, 'paid_status' => 'unallocated']);
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id, 'company_id' => null, 'paid_status' => 'unallocated']);
    }

    public function test_customer_statement_returns_running_invoice_and_payment_balance(): void
    {
        $company = Company::create(['name' => 'Statement Tenant', 'code' => 'STATEMENT-TENANT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Statement Customer', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-STATEMENT', 'date' => '2026-09-05', 'status' => 1, 'total_amount' => 100]);
        Payment::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'paid_status' => 'partial_paid', 'paid_amount' => 25, 'due_amount' => 75, 'total_amount' => 100, 'is_reversed' => false]);
        $token = $user->createToken('statement-read-test', ['accounting:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/accounting/customers/'.$customer->id.'/statement?from=2026-09-01&to=2026-09-30');

        $response->assertOk()->assertJsonPath('summary.opening_balance', 0)->assertJsonPath('summary.closing_balance', 75);
        $this->assertSame(['invoice', 'payment'], collect($response->json('data'))->pluck('type')->all());
        $this->assertEquals(75.0, (float) $response->json('data.1.balance'));
    }

    public function test_supplier_statement_returns_running_invoice_and_payment_balance(): void
    {
        $company = Company::create(['name' => 'AP Statement Tenant', 'code' => 'AP-STATEMENT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Statement Supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-STATEMENT', 'invoice_date' => '2026-09-05', 'status' => 'approved', 'total_amount' => 100]);
        SupplierPayment::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'purchase_invoice_id' => $invoice->id, 'payment_no' => 'PAY-STATEMENT', 'payment_date' => '2026-09-10', 'status' => 'approved', 'amount' => 30, 'is_reversed' => false]);
        $token = $user->createToken('supplier-statement-read-test', ['accounting:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/accounting/suppliers/'.$supplier->id.'/statement?from=2026-09-01&to=2026-09-30');

        $response->assertOk()->assertJsonPath('summary.opening_balance', 0)->assertJsonPath('summary.closing_balance', 70);
        $this->assertSame(['purchase_invoice', 'supplier_payment'], collect($response->json('data'))->pluck('type')->all());
    }

    public function test_statements_do_not_double_count_payment_allocations(): void
    {
        $company = Company::create(['name' => 'Allocation Statement Tenant', 'code' => 'ALLOC-STATEMENT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Allocated Customer', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-ALLOC', 'date' => '2026-09-05', 'status' => 1, 'total_amount' => 100]);
        $payment = Payment::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'paid_status' => 'paid', 'paid_amount' => 40, 'due_amount' => 0, 'total_amount' => 40, 'is_reversed' => false]);
        app(CustomerPaymentAllocationService::class)->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => 40]]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Allocated Supplier', 'is_active' => true]);
        $purchaseInvoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-ALLOC', 'invoice_date' => '2026-09-05', 'status' => 'approved', 'total_amount' => 100]);
        $supplierPayment = SupplierPayment::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'payment_no' => 'PAY-ALLOC', 'payment_date' => '2026-09-06', 'status' => 'approved', 'amount' => 40, 'is_reversed' => false]);
        $supplierAllocation = app(SupplierPaymentService::class)->allocate($supplierPayment, $purchaseInvoice->id, 40);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'allocation_status' => 'fully_allocated']);
        $this->assertDatabaseHas('supplier_payments', ['id' => $supplierPayment->id, 'allocation_status' => 'fully_allocated']);
        $token = $user->createToken('allocation-statement-read-test', ['accounting:read'])->plainTextToken;

        $customerFeed = $this->withToken($token)->getJson('/api/accounting/customer-payments?allocation_status=fully_allocated');
        $customerFeed->assertOk()->assertJsonPath('data.0.allocation_status', 'fully_allocated');
        $supplierFeed = $this->withToken($token)->getJson('/api/accounting/supplier-payments?allocation_status=fully_allocated');
        $supplierFeed->assertOk()->assertJsonPath('data.0.allocation_status', 'fully_allocated');

        $customerResponse = $this->withToken($token)->getJson('/api/accounting/customers/'.$customer->id.'/statement?from=2026-09-01&to=2026-09-30');
        $customerResponse->assertOk()->assertJsonPath('summary.closing_balance', 60)->assertJsonPath('data.1.allocated_amount', 40)->assertJsonPath('data.1.allocation_count', 1);
        $this->assertSame(['invoice', 'payment'], collect($customerResponse->json('data'))->pluck('type')->all());

        $supplierResponse = $this->withToken($token)->getJson('/api/accounting/suppliers/'.$supplier->id.'/statement?from=2026-09-01&to=2026-09-30');
        $supplierResponse->assertOk()->assertJsonPath('summary.closing_balance', 60)->assertJsonPath('data.1.allocated_amount', 40)->assertJsonPath('data.1.allocation_count', 1);
        $this->assertSame(['purchase_invoice', 'supplier_payment'], collect($supplierResponse->json('data'))->pluck('type')->all());

        app(CustomerPaymentAllocationService::class)->void($payment->allocations()->firstOrFail()->id, 'Reopened for settlement');
        app(SupplierPaymentService::class)->voidAllocation($supplierAllocation->id, 'Reopened for settlement');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'allocation_status' => 'unallocated']);
        $this->assertDatabaseHas('supplier_payments', ['id' => $supplierPayment->id, 'allocation_status' => 'unallocated']);
    }

    public function test_cross_currency_allocations_store_payment_and_invoice_amounts(): void
    {
        $company = Company::create(['name' => 'FX Allocation Tenant', 'code' => 'FX-ALLOC']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'FX Customer', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-FX', 'date' => '2026-09-05', 'status' => 1, 'currency_code' => 'EUR', 'total_amount' => 90]);
        $payment = Payment::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'paid_status' => 'unallocated', 'paid_amount' => 100, 'due_amount' => 0, 'total_amount' => 100, 'currency_code' => 'USD', 'is_reversed' => false]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'is_active' => true]);
        ExchangeRate::create(['from_currency_id' => $usd->id, 'to_currency_id' => $eur->id, 'rate' => 0.9, 'effective_date' => '2026-09-15']);

        $allocation = app(CustomerPaymentAllocationService::class)->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => 90]]);

        $allocation = $allocation->allocations->firstOrFail();
        $this->assertEquals(90.0, (float) $allocation->amount);
        $this->assertEquals(100.0, (float) $allocation->payment_amount);
        $this->assertEquals(0.9, (float) $allocation->exchange_rate);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'allocation_status' => 'fully_allocated']);

        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'FX Supplier', 'is_active' => true]);
        $purchaseInvoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-FX', 'invoice_date' => '2026-09-05', 'status' => 'approved', 'currency_code' => 'EUR', 'total_amount' => 90]);
        $supplierPayment = SupplierPayment::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'payment_no' => 'PAY-FX', 'payment_date' => '2026-09-15', 'status' => 'approved', 'amount' => 100, 'currency_code' => 'USD', 'is_reversed' => false]);
        $supplierPaymentResult = app(SupplierPaymentService::class)->allocate($supplierPayment, $purchaseInvoice->id, 90, null, 0.9);

        $this->assertEquals(90.0, (float) $supplierPaymentResult->amount);
        $this->assertEquals(100.0, (float) $supplierPaymentResult->payment_amount);
        $this->assertEquals(0.9, (float) $supplierPaymentResult->exchange_rate);
        $this->assertDatabaseHas('supplier_payments', ['id' => $supplierPayment->id, 'allocation_status' => 'fully_allocated']);
    }

    public function test_cross_currency_settlement_posts_and_reverses_realized_fx(): void
    {
        $company = Company::create(['name' => 'Realized FX Co', 'code' => 'REALIZED-FX', 'base_currency' => 'USD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:write']);
        $receivable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200', 'name' => 'AR', 'account_type' => 'asset', 'is_active' => true]);
        $payable = ChartOfAccount::create(['company_id' => $company->id, 'code' => '2100', 'name' => 'AP', 'account_type' => 'liability', 'is_active' => true]);
        $gain = ChartOfAccount::create(['company_id' => $company->id, 'code' => '7100', 'name' => 'FX Gain', 'account_type' => 'income', 'is_active' => true]);
        $loss = ChartOfAccount::create(['company_id' => $company->id, 'code' => '8100', 'name' => 'FX Loss', 'account_type' => 'expense', 'is_active' => true]);
        foreach ([['accounts_receivable', $receivable->id], ['accounts_payable', $payable->id], ['fx_gain', $gain->id], ['fx_loss', $loss->id]] as [$key, $accountId]) AccountMapping::create(['company_id' => $company->id, 'mapping_key' => $key, 'account_id' => $accountId]);

        $customer = Customer::create(['company_id' => $company->id, 'name' => 'FX Customer', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-REALIZED-FX', 'date' => now()->toDateString(), 'status' => 1, 'currency_code' => 'EUR', 'exchange_rate' => 1, 'total_amount' => 90]);
        $payment = Payment::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'paid_status' => 'unallocated', 'paid_amount' => 100, 'due_amount' => 0, 'total_amount' => 100, 'currency_code' => 'USD', 'exchange_rate' => 1.2, 'base_amount' => 120, 'is_reversed' => false]);
        $customerAllocation = app(CustomerPaymentAllocationService::class)->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => 90, 'exchange_rate' => 0.9]], 'REALIZED-CUSTOMER-FX');

        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'FX Supplier', 'is_active' => true]);
        $purchaseInvoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_no' => 'PINV-REALIZED-FX', 'invoice_date' => now()->toDateString(), 'status' => 'approved', 'currency_code' => 'EUR', 'exchange_rate' => 1, 'total_amount' => 90]);
        $supplierPayment = SupplierPayment::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'payment_no' => 'PAY-REALIZED-FX', 'payment_date' => now()->toDateString(), 'status' => 'approved', 'amount' => 100, 'currency_code' => 'USD', 'exchange_rate' => 1.2, 'base_amount' => 120, 'is_reversed' => false]);
        $supplierAllocation = app(SupplierPaymentService::class)->allocate($supplierPayment, $purchaseInvoice->id, 90, 'REALIZED-SUPPLIER-FX', 0.9);

        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseHas('journal_entries', ['source_type' => CustomerPaymentAllocation::class, 'source_id' => $customerAllocation->allocations->first()->id, 'external_reference' => 'FX-CUSTOMER-SETTLEMENT-'.$customerAllocation->allocations->first()->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => SupplierPaymentAllocation::class, 'source_id' => $supplierAllocation->id, 'external_reference' => 'FX-SUPPLIER-SETTLEMENT-'.$supplierAllocation->id]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $receivable->id, 'debit' => 30]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $gain->id, 'credit' => 30]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $loss->id, 'debit' => 30]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $payable->id, 'credit' => 30]);

        app(CustomerPaymentAllocationService::class)->void($customerAllocation->allocations->first()->id, 'Corrected settlement');
        app(SupplierPaymentService::class)->voidAllocation($supplierAllocation->id, 'Corrected settlement');
        $this->assertDatabaseCount('journal_entries', 4);
        $this->assertDatabaseHas('journal_entries', ['source_type' => CustomerPaymentAllocation::class, 'source_id' => $customerAllocation->allocations->first()->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('journal_entries', ['source_type' => SupplierPaymentAllocation::class, 'source_id' => $supplierAllocation->id, 'status' => 'reversed']);
    }
}
