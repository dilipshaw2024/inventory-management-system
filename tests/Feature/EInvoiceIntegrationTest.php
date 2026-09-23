<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EInvoiceSubmission;
use App\Models\EInvoiceProviderSetting;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_invoice_creates_idempotent_hashed_e_invoice_envelope(): void
    {
        $company = Company::create(['name' => 'E-Invoice Co', 'code' => 'EINV-API']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'E-Invoice Customer', 'tax_number' => 'TAX-123', 'status' => 1]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'E-Invoice Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'E-Invoice Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'E-Invoice Item', 'sku' => 'EINV-SKU', 'quantity' => 0, 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-EINV-1', 'date' => '2026-09-18', 'status' => 1, 'subtotal_amount' => 100, 'tax_amount' => 18, 'total_amount' => 118, 'currency_code' => 'INR']);
        $invoice->invoice_details()->create(['date' => '2026-09-18', 'product_id' => $product->id, 'selling_qty' => 2, 'unit_price' => 50, 'selling_price' => 100, 'tax_rate' => 18, 'tax_amount' => 18, 'status' => 1]);
        $token = $user->createToken('einvoice-test', ['accounting:write', 'accounting:read'])->plainTextToken;

        $first = $this->withToken($token)->postJson('/api/accounting/invoices/'.$invoice->id.'/e-invoice', ['provider' => 'generic']);
        $first->assertCreated()->assertJsonPath('data.status', 'prepared')->assertJsonPath('data.payload.invoice_number', 'INV-EINV-1')->assertJsonPath('data.payload.totals.total', 118);
        $hash = $first->json('data.payload_hash');
        $this->assertSame(64, strlen($hash));
        $second = $this->withToken($token)->postJson('/api/accounting/invoices/'.$invoice->id.'/e-invoice', ['provider' => 'generic']);
        $second->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.payload_hash', $hash);
        $this->withToken($token)->postJson('/api/accounting/e-invoices/'.$first->json('data.id').'/submit')
            ->assertStatus(422)->assertJsonPath('message', 'The configured e-invoice provider does not support live submission.');
        $submission = EInvoiceSubmission::findOrFail($first->json('data.id'));
        $submission->update(['status' => 'submitted', 'external_reference' => 'EXT-EINV-1']);
        config(['integrations.e_invoice_callback_secret' => 'callback-secret']);
        $callback = ['external_reference' => 'EXT-EINV-1', 'status' => 'accepted', 'response' => ['provider_id' => 'GOV-1']];
        $body = json_encode($callback);
        $this->withHeaders(['X-ERP-EINVOICE-SIGNATURE' => hash_hmac('sha256', $body, 'callback-secret')])
            ->postJson('/api/integration/e-invoices/generic/callback', $callback)
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.provider_response.provider_id', 'GOV-1');
        $this->assertDatabaseCount('e_invoice_submissions', 1);
    }

    public function test_e_invoice_requires_approved_invoice_and_known_provider(): void
    {
        $company = Company::create(['name' => 'Pending E-Invoice Co', 'code' => 'EINV-PENDING']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'INV-PENDING', 'status' => 0, 'total_amount' => 10]);
        $token = $user->createToken('einvoice-pending-test', ['accounting:write'])->plainTextToken;
        $this->withToken($token)->postJson('/api/accounting/invoices/'.$invoice->id.'/e-invoice')->assertStatus(422)->assertJsonPath('message', 'Only approved sales invoices can be prepared for e-invoicing.');
    }

    public function test_http_provider_settings_are_company_scoped_encrypted_and_used_for_submission(): void
    {
        $company = Company::create(['name' => 'Configured E-Invoice Co', 'code' => 'EINV-CONFIG']);
        $otherCompany = Company::create(['name' => 'Other E-Invoice Co', 'code' => 'EINV-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $token = $user->createToken('einvoice-settings', ['accounting:read', 'accounting:write', 'integration:read', 'integration:write'])->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/accounting/e-invoice-providers', [
            'provider' => 'HTTP',
            'connection_config' => ['endpoint' => 'https://tenant-gateway.example.test/e-invoices', 'token' => 'tenant-secret', 'timeout' => 11],
        ])->assertCreated()->assertJsonPath('data.provider', 'http');
        $this->assertArrayNotHasKey('connection_config', $created->json('data'));
        $settingId = $created->json('data.id');
        $this->assertNotSame('tenant-secret', (string) $this->app['db']->table('e_invoice_provider_settings')->where('id', $settingId)->value('connection_config'));
        $this->assertSame(0, EInvoiceProviderSetting::where('company_id', $otherCompany->id)->count());
        $this->withToken($token)->getJson('/api/accounting/e-invoice-providers')->assertOk()->assertJsonPath('data.0.id', $settingId);
        $this->withToken($token)->getJson('/api/integration/providers')->assertOk()->assertJsonPath('data.e_invoice.providers.1.ready', true);

        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'INV-TENANT', 'status' => 1, 'total_amount' => 10]);
        $submission = EInvoiceSubmission::create([
            'company_id' => $company->id, 'invoice_id' => $invoice->id, 'provider' => 'http', 'payload_hash' => str_repeat('c', 64),
            'payload' => ['invoice_number' => 'INV-TENANT'], 'status' => 'prepared',
        ]);
        Http::fake(['https://tenant-gateway.example.test/*' => Http::response(['status' => 'accepted', 'reference' => 'TENANT-1'], 200)]);
        $result = (new \App\Services\Integrations\HttpEInvoiceProvider())->submit($submission);
        $this->assertSame('TENANT-1', $result['external_reference']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://tenant-gateway.example.test/e-invoices' && $request->hasHeader('Authorization', 'Bearer tenant-secret'));
        $this->withToken($token)->postJson('/api/accounting/e-invoice-providers/'.$settingId.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse(EInvoiceProviderSetting::findOrFail($settingId)->is_active);
    }
}
