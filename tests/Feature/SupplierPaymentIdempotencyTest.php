<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierPaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payment_creation_replay_returns_the_original_payment(): void
    {
        $company = Company::create(['name' => 'Payment Replay Co', 'code' => 'PAYMENT-REPLAY', 'base_currency' => 'USD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Payment Replay Supplier', 'is_active' => true]);
        Sanctum::actingAs($user, ['accounting:write']);
        $payload = ['external_reference' => 'PAYMENT-REPLAY-1', 'supplier_id' => $supplier->id, 'payment_date' => '2026-09-20', 'amount' => 125, 'method' => 'bank', 'currency_code' => 'USD', 'reference' => 'BANK-REPLAY-1'];

        $created = $this->postJson('/api/accounting/supplier-payments', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/accounting/supplier-payments', $payload + ['amount' => 999]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, SupplierPayment::where('company_id', $company->id)->count());
    }
}
