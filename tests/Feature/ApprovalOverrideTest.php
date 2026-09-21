<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_override_is_independently_decided_and_consumed_by_guarded_approval(): void
    {
        $company = Company::create(['name' => 'Override Co', 'code' => 'OVERRIDE-CO']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $decider = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Override Supplier', 'is_active' => true]);
        $order = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-OVERRIDE',
            'date' => '2026-09-18', 'status' => 'submitted', 'created_by' => $requester->id,
        ]);
        ApprovalPolicy::create([
            'company_id' => $company->id, 'document_type' => PurchaseOrder::class,
            'required_permission' => 'purchasing.approve', 'approval_step' => 1,
        ]);

        Sanctum::actingAs($requester, ['integration:write']);
        $created = $this->postJson('/api/integration/security/approval-overrides', [
            'document_type' => PurchaseOrder::class, 'document_id' => $order->id,
            'approval_step' => 1, 'reason' => 'Emergency approval coverage is required.',
        ]);
        $created->assertCreated()->assertJsonPath('status', 'pending');
        $overrideId = $created->json('data.id');

        $created->assertJsonPath('data.requested_by', $requester->id);
        Sanctum::actingAs($decider, ['integration:write']);
        $this->postJson('/api/integration/security/approval-overrides/'.$overrideId.'/approve', [
            'decision_reason' => 'Emergency coverage verified by an independent approver.',
        ])->assertOk()->assertJsonPath('status', 'approved');

        Sanctum::actingAs($decider, ['purchasing:write']);
        $this->postJson('/api/integration/purchase-orders/'.$order->id.'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('approval_overrides', [
            'id' => $overrideId, 'status' => 'approved', 'consumed_by' => $decider->id,
        ]);
    }
}
