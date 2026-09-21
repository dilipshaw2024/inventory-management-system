<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierCorrectiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_corrective_action_has_idempotent_create_and_audited_lifecycle(): void
    {
        $company = Company::create(['name' => 'Supplier Corrective Co', 'code' => 'SUPPLIER-CORRECTIVE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Corrective Supplier', 'is_active' => true]);
        Sanctum::actingAs($user, ['purchasing:read', 'purchasing:write']);

        $payload = ['external_reference' => 'CAPA-001', 'supplier_id' => $supplier->id, 'title' => 'Late delivery corrective action', 'issue_type' => 'delivery', 'severity' => 'high', 'due_date' => '2026-10-01', 'description' => 'Improve confirmed delivery dates.'];
        $created = $this->postJson('/api/integration/supplier-corrective-actions', $payload)->assertCreated()->assertJsonPath('status', 'created');
        $id = $created->json('data.id');
        $this->postJson('/api/integration/supplier-corrective-actions', $payload)->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $id);
        $this->patchJson('/api/integration/supplier-corrective-actions/'.$id, ['status' => 'closed', 'resolution' => 'Supplier added a delivery-control review.'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/integration/supplier-corrective-actions?status=closed')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/integration/supplier-corrective-actions/summary?from=2026-01-01&to=2026-03-31&include_trend=1')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.closed', 1)->assertJsonPath('data.overdue', 0)->assertJsonCount(3, 'data.trend');
        $this->getJson('/api/integration/supplier-performance?from=2026-01-01&to=2026-03-31&include_trend=1')->assertOk()->assertJsonPath('meta.trend_granularity', 'month')->assertJsonCount(3, 'trend');
        $this->assertDatabaseHas('supplier_corrective_actions', ['id' => $id, 'status' => 'closed', 'closed_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier_corrective_action.updated', 'auditable_id' => $id]);
    }
}
