<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalEscalation;
use App\Models\Company;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ApprovalEscalationTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_approval_uses_configured_escalation_permission_tier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));
        $company = Company::create(['name' => 'Approval Tier Co', 'code' => 'APPROVAL-TIER']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Approval Supplier', 'is_active' => true]);
        $permission = Permission::create(['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting']);
        $role = Role::create(['code' => 'tier-two-approver', 'name' => 'Tier 2 approver', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $approver = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $approver->roles()->attach($role->id);
        $purchaseOrder = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-APPROVAL-TIER',
            'date' => '2026-09-17', 'status' => 'submitted', 'created_by' => $approver->id,
            'created_at' => Carbon::parse('2026-09-18 08:00:00'), 'updated_at' => Carbon::parse('2026-09-18 08:00:00'),
        ]);
        ApprovalPolicy::create([
            'company_id' => $company->id, 'document_type' => PurchaseOrder::class, 'required_permission' => 'purchasing.manage',
            'approval_step' => 1, 'escalation_after_hours' => 1, 'escalation_permissions' => ['accounting.manage'], 'max_escalation_level' => 2,
        ]);

        $this->artisan('erp:approvals:escalate', ['--company' => $company->id])->assertExitCode(0);

        $notification = $approver->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame($purchaseOrder->id, $notification->data['document_id']);
        $this->assertSame(2, $notification->data['escalation_level']);
        $this->assertSame('accounting.manage', $notification->data['required_permission']);
        $this->assertSame(1, $approver->notifications()->whereJsonContains('data->escalation_level', 2)->count());
        $escalation = ApprovalEscalation::where('company_id', $company->id)->where('user_id', $approver->id)->firstOrFail();
        $this->assertSame('pending', $escalation->status);
        Sanctum::actingAs($approver, ['integration:read', 'integration:write']);
        $this->getJson('/api/integration/security/approval-escalations')->assertOk()->assertJsonPath('data.0.id', $escalation->id)->assertJsonPath('data.0.status', 'pending');
        $this->postJson('/api/integration/security/approval-escalations/'.$escalation->id.'/acknowledge', ['acknowledgment_note' => 'I have reviewed the overdue approval.'])
            ->assertOk()->assertJsonPath('status', 'acknowledged')->assertJsonPath('data.status', 'acknowledged');
        $this->assertDatabaseHas('approval_escalations', ['id' => $escalation->id, 'status' => 'acknowledged', 'acknowledgment_note' => 'I have reviewed the overdue approval.']);
        Carbon::setTestNow();
    }

    public function test_higher_escalation_tier_supersedes_prior_pending_alert_for_same_recipient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));
        $company = Company::create(['name' => 'Approval Supersession Co', 'code' => 'APPROVAL-SUPERSESSION']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Supersession Supplier', 'is_active' => true]);
        $basePermission = Permission::create(['code' => 'purchasing.manage', 'name' => 'Manage purchasing', 'module' => 'purchasing']);
        $tierPermission = Permission::create(['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting']);
        $role = Role::create(['code' => 'supersession-approver', 'name' => 'Supersession approver', 'is_active' => true]);
        $role->permissions()->attach([$basePermission->id, $tierPermission->id]);
        $approver = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $approver->roles()->attach($role->id);
        $purchaseOrder = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'po_no' => 'PO-APPROVAL-SUPERSESSION',
            'date' => '2026-09-18', 'status' => 'submitted', 'created_by' => $approver->id,
            'created_at' => Carbon::parse('2026-09-18 10:00:00'), 'updated_at' => Carbon::parse('2026-09-18 10:00:00'),
        ]);
        ApprovalPolicy::create([
            'company_id' => $company->id, 'document_type' => PurchaseOrder::class, 'required_permission' => 'purchasing.manage',
            'approval_step' => 1, 'escalation_after_hours' => 1, 'escalation_permissions' => ['accounting.manage'], 'max_escalation_level' => 2,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 11:30:00'));
        $this->artisan('erp:approvals:escalate', ['--company' => $company->id])->assertExitCode(0);
        $first = ApprovalEscalation::where('document_id', $purchaseOrder->id)->where('escalation_level', 1)->firstOrFail();
        $this->assertSame('pending', $first->status);

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:30:00'));
        $this->artisan('erp:approvals:escalate', ['--company' => $company->id])->assertExitCode(0);
        $this->assertDatabaseHas('approval_escalations', ['id' => $first->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('approval_escalations', ['document_id' => $purchaseOrder->id, 'escalation_level' => 2, 'status' => 'pending']);
        Carbon::setTestNow();
    }
}
