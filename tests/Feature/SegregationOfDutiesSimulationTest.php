<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalOverride;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SegregationOfDutiesSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_evaluates_maker_checker_and_approval_permissions_without_mutation(): void
    {
        $company = Company::create(['name' => 'SOD Co', 'code' => 'SOD-CO']);
        $integration = User::factory()->create(['company_id' => $company->id]);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'purchasing.approve', 'name' => 'Approve purchasing', 'module' => 'purchasing']);
        $role = Role::create(['code' => 'purchasing-approver', 'name' => 'Purchasing approver', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $approver->roles()->attach($role->id);
        ApprovalPolicy::create([
            'company_id' => $company->id, 'document_type' => 'purchase_order',
            'required_permission' => 'purchasing.approve', 'approval_step' => 1,
        ]);
        Sanctum::actingAs($integration, ['integration:read']);

        $sameMaker = $this->postJson('/api/integration/security/sod/simulate', [
            'document_type' => 'purchase_order', 'creator_id' => $creator->id, 'approver_id' => $creator->id,
        ]);
        $sameMaker->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.maker_checker_conflict', true);

        $valid = $this->postJson('/api/integration/security/sod/simulate', [
            'document_type' => 'purchase_order', 'creator_id' => $creator->id, 'approver_id' => $approver->id,
        ]);
        $valid->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.approval_required', true)
            ->assertJsonPath('data.approval_steps.0.approver_has_permission', true);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_integration_clients_can_manage_company_role_conflicts(): void
    {
        $company = Company::create(['name' => 'SOD Admin Co', 'code' => 'SOD-ADMIN']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $makerRole = Role::create(['code' => 'sod-maker', 'name' => 'SOD maker', 'is_active' => true]);
        $checkerRole = Role::create(['code' => 'sod-checker', 'name' => 'SOD checker', 'is_active' => true]);
        Sanctum::actingAs($admin, ['integration:write', 'integration:read']);

        $created = $this->postJson('/api/integration/security/role-conflicts', [
            'role_id' => $makerRole->id, 'conflicting_role_id' => $checkerRole->id, 'reason' => 'Separate incompatible duties.',
        ])->assertCreated()->assertJsonPath('status', 'created');
        $conflictId = $created->json('data.id');
        $this->assertDatabaseHas('role_conflicts', ['id' => $conflictId, 'company_id' => $company->id, 'is_active' => true]);

        $this->postJson('/api/integration/security/role-conflicts/'.$conflictId.'/deactivate')
            ->assertOk()->assertJsonPath('status', 'deactivated');
        $this->assertDatabaseHas('role_conflicts', ['id' => $conflictId, 'is_active' => false]);
    }

    public function test_integration_clients_can_read_sod_exception_analytics(): void
    {
        $company = Company::create(['name' => 'SOD Analytics Co', 'code' => 'SOD-ANALYTICS']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $decider = User::factory()->create(['company_id' => $company->id]);
        ApprovalOverride::create([
            'company_id' => $company->id, 'document_type' => 'purchase_order', 'document_id' => 101,
            'approval_step' => 1, 'reason' => 'Pending coverage.', 'requested_by' => $requester->id, 'status' => 'pending',
        ]);
        ApprovalOverride::create([
            'company_id' => $company->id, 'document_type' => 'purchase_order', 'document_id' => 102,
            'approval_step' => 1, 'reason' => 'Approved coverage.', 'requested_by' => $requester->id,
            'status' => 'approved', 'approved_by' => $decider->id, 'approved_at' => now(), 'consumed_at' => now(), 'consumed_by' => $decider->id,
        ]);
        ApprovalOverride::create([
            'company_id' => $company->id, 'document_type' => 'sales_order', 'document_id' => 103,
            'approval_step' => 1, 'reason' => 'Rejected coverage.', 'requested_by' => $requester->id,
            'status' => 'rejected', 'approved_by' => $decider->id, 'approved_at' => now(),
        ]);

        Sanctum::actingAs($requester, ['integration:read']);
        $this->getJson('/api/integration/security/sod/exceptions?document_type=purchase_order')
            ->assertOk()
            ->assertJsonPath('summary.total_requests', 2)
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.consumed', 1)
            ->assertJsonPath('summary.active_approved', 0)
            ->assertJsonCount(2, 'data');
    }
}
