<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyTaxRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_tax_registrations_are_idempotent_primary_scoped_and_audited(): void
    {
        $company = Company::create(['name' => 'Tax Registration Co', 'code' => 'TAX-REGISTRATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);
        $payload = ['jurisdiction' => 'IN-KA', 'scheme' => 'GSTIN', 'registration_number' => '29ABCDE1234F1Z5', 'legal_name' => 'Tax Registration Co', 'effective_from' => '2026-01-01', 'external_reference' => 'tax-reg-1', 'is_primary' => true];

        $created = $this->postJson('/api/accounting/company-tax-registrations', $payload)->assertCreated()->assertJsonPath('data.is_primary', true);
        $id = $created->json('data.id');
        $this->postJson('/api/accounting/company-tax-registrations', $payload)->assertOk()->assertJsonPath('status', 'existing')->assertJsonPath('data.id', $id);
        $second = $this->postJson('/api/accounting/company-tax-registrations', array_merge($payload, ['jurisdiction' => 'IN-MH', 'registration_number' => '27ABCDE1234F1Z7', 'external_reference' => 'tax-reg-2', 'is_primary' => true]))->assertCreated();
        $this->assertDatabaseHas('company_tax_registrations', ['id' => $id, 'is_primary' => false]);
        $this->patchJson('/api/accounting/company-tax-registrations/'.$id, ['legal_name' => 'Updated Tax Registration Co'])->assertOk()->assertJsonPath('data.legal_name', 'Updated Tax Registration Co');
        $this->postJson('/api/accounting/company-tax-registrations/'.$second->json('data.id').'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'company_tax_registration.created']);
    }

    public function test_company_tax_registrations_cannot_cross_tenants_or_accept_invalid_periods(): void
    {
        $company = Company::create(['name' => 'Tax Registration Owner', 'code' => 'TAX-REG-OWNER']);
        $other = Company::create(['name' => 'Other Tax Company', 'code' => 'TAX-REG-OTHER']);
        $owner = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $other->id]);
        Sanctum::actingAs($owner, ['accounting:write']);
        $this->postJson('/api/accounting/company-tax-registrations', ['jurisdiction' => 'US-CA', 'scheme' => 'SALES_TAX', 'registration_number' => 'CA-100', 'effective_from' => '2026-06-01', 'effective_until' => '2026-01-01'])->assertStatus(422);
        $registration = $this->postJson('/api/accounting/company-tax-registrations', ['jurisdiction' => 'US-CA', 'scheme' => 'SALES_TAX', 'registration_number' => 'CA-100'])->assertCreated();
        Sanctum::actingAs($otherUser, ['accounting:write']);
        $this->patchJson('/api/accounting/company-tax-registrations/'.$registration->json('data.id'), ['legal_name' => 'Cross Tenant'])->assertNotFound();
    }
}
