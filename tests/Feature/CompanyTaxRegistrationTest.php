<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\TaxFiling;
use App\Models\Permission;
use App\Models\Role;
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

    public function test_tax_filing_binds_active_registration_and_rejects_wrong_jurisdiction(): void
    {
        $company = Company::create(['name' => 'Tax Filing Registration Co', 'code' => 'TAX-FILING-REG']);
        $registration = $company->taxRegistrations()->create(['jurisdiction' => 'EU-DE', 'scheme' => 'VAT', 'registration_number' => 'DE123456789', 'effective_from' => '2026-01-01', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:write', 'accounting:read']);
        $this->postJson('/api/accounting/tax-filings', ['from' => '2026-01-01', 'to' => '2026-01-31', 'jurisdiction' => 'EU-DE', 'tax_registration_id' => $registration->id, 'external_reference' => 'filing-registration-1'])->assertCreated();
        $filing = TaxFiling::where('external_reference', 'filing-registration-1')->firstOrFail();
        $this->assertSame($registration->id, (int) $filing->tax_registration_id);
        $this->assertSame('DE123456789', $filing->snapshot_payload['tax_registration']['registration_number']);
        $this->postJson('/api/accounting/tax-filings', ['from' => '2026-01-01', 'to' => '2026-01-31', 'jurisdiction' => 'EU-FR', 'tax_registration_id' => $registration->id, 'external_reference' => 'filing-registration-2'])->assertStatus(422);
    }

    public function test_browser_can_create_and_deactivate_tax_registration(): void
    {
        $company = Company::create(['name' => 'Tax Registration Browser Co', 'code' => 'TAX-REG-BROWSER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting']);
        $role = Role::create(['code' => 'tax-registration-admin', 'name' => 'Tax registration admin', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $this->actingAs($user)->post('/erp/accounting/tax-registrations', ['jurisdiction' => 'US-CA', 'scheme' => 'SALES_TAX', 'registration_number' => 'CA-200', 'is_primary' => 1])->assertRedirect();
        $registration = $company->taxRegistrations()->firstOrFail();
        $this->actingAs($user)->post('/erp/accounting/tax-registrations/'.$registration->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('company_tax_registrations', ['id' => $registration->id, 'is_active' => false]);
    }
}
