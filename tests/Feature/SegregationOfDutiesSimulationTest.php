<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalOverride;
use App\Models\Company;
use App\Models\DocumentRevision;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
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

    public function test_security_admin_can_restore_supplier_and_customer_revisions_with_audit(): void
    {
        $company = Company::create(['name' => 'Restore Masters Co', 'code' => 'RESTORE-MASTERS']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'users.manage', 'name' => 'Manage users', 'module' => 'security']);
        $role = Role::create(['code' => 'security-admin', 'name' => 'Security admin', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Deleted supplier', 'is_active' => false]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Deleted customer', 'is_active' => false]);
        $supplier->delete();
        $customer->delete();
        $supplierRevision = DocumentRevision::create(['company_id' => $company->id, 'document_type' => (new Supplier)->getMorphClass(), 'document_id' => $supplier->id, 'version' => 1, 'old_values' => ['name' => 'Restored supplier', 'is_active' => true], 'new_values' => ['name' => 'Deleted supplier', 'is_active' => false], 'changed_by' => $admin->id, 'changed_at' => now()]);
        $customerRevision = DocumentRevision::create(['company_id' => $company->id, 'document_type' => (new Customer)->getMorphClass(), 'document_id' => $customer->id, 'version' => 1, 'old_values' => ['name' => 'Restored customer', 'is_active' => true], 'new_values' => ['name' => 'Deleted customer', 'is_active' => false], 'changed_by' => $admin->id, 'changed_at' => now()]);

        $this->actingAs($admin)->post('/erp/security/audit/revisions/'.$supplierRevision->id.'/restore', ['reason' => 'Restore approved supplier master.'])->assertRedirect();
        $this->actingAs($admin)->post('/erp/security/audit/revisions/'.$customerRevision->id.'/restore', ['reason' => 'Restore approved customer master.'])->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Restored supplier', 'is_active' => true, 'deleted_at' => null]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Restored customer', 'is_active' => true, 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_revision.restored', 'auditable_type' => (new Supplier)->getMorphClass(), 'auditable_id' => $supplier->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_revision.restored', 'auditable_type' => (new Customer)->getMorphClass(), 'auditable_id' => $customer->id]);
    }


    public function test_security_admin_can_restore_product_lifecycle_and_costing_fields_without_stock_mutation(): void
    {
        $company = Company::create(['name' => 'Restore Product Co', 'code' => 'RESTORE-PRODUCT']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'users.manage', 'name' => 'Manage users', 'module' => 'security']);
        $role = Role::create(['code' => 'product-security-admin', 'name' => 'Product security admin', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Product supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Product unit', 'code' => 'PROD-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Product category', 'code' => 'PROD-CAT', 'is_active' => true, 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Archived product', 'sku' => 'RESTORE-PROD', 'quantity' => 7, 'status' => 0,
            'product_type' => 'stock', 'lifecycle_status' => 'archived', 'can_purchase' => false, 'can_sell' => false,
            'is_stock_item' => true, 'costing_method' => 'fifo', 'standard_cost' => 4, 'purchase_price' => 4, 'sales_price' => 6,
        ]);
        $product->delete();
        $revision = DocumentRevision::create([
            'company_id' => $company->id, 'document_type' => (new Product)->getMorphClass(), 'document_id' => $product->id,
            'version' => 1, 'old_values' => [
                'name' => 'Restored product', 'status' => 1, 'lifecycle_status' => 'active', 'can_purchase' => true,
                'can_sell' => true, 'costing_method' => 'standard', 'standard_cost' => 5,
            ], 'new_values' => ['name' => 'Archived product', 'status' => 0, 'lifecycle_status' => 'archived'],
            'changed_by' => $admin->id, 'changed_at' => now(),
        ]);

        $this->actingAs($admin)->post('/erp/security/audit/revisions/'.$revision->id.'/restore', ['reason' => 'Restore approved product master.'])->assertRedirect();
        $restored = Product::withTrashed()->findOrFail($product->id);
        $this->assertSame('Restored product', $restored->name);
        $this->assertSame('active', $restored->lifecycle_status);
        $this->assertTrue((bool) $restored->can_purchase);
        $this->assertSame(7.0, (float) $restored->quantity);
        $this->assertNull($restored->deleted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_revision.restored', 'auditable_type' => (new Product)->getMorphClass(), 'auditable_id' => $product->id]);
    }


    public function test_security_admin_can_restore_catalog_master_revisions(): void
    {
        $company = Company::create(['name' => 'Restore Catalog Co', 'code' => 'RESTORE-CATALOG']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'users.manage', 'name' => 'Manage users', 'module' => 'security']);
        $role = Role::create(['code' => 'catalog-security-admin', 'name' => 'Catalog security admin', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Deleted unit', 'code' => 'DEL-UNIT', 'status' => 0]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Deleted category', 'code' => 'DEL-CAT', 'is_active' => false, 'status' => 0]);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Deleted brand', 'code' => 'DEL-BRAND', 'is_active' => false]);
        $unit->delete();
        $category->delete();
        $brand->delete();
        $revisions = [
            [$unit, 'Restored unit', (new Unit)->getMorphClass(), ['name' => 'Restored unit', 'status' => 1]],
            [$category, 'Restored category', (new Category)->getMorphClass(), ['name' => 'Restored category', 'is_active' => true, 'status' => 1]],
            [$brand, 'Restored brand', (new Brand)->getMorphClass(), ['name' => 'Restored brand', 'is_active' => true]],
        ];
        foreach ($revisions as [$record, $name, $type, $oldValues]) {
            $revision = DocumentRevision::create([
                'company_id' => $company->id, 'document_type' => $type, 'document_id' => $record->id,
                'version' => 1, 'old_values' => $oldValues, 'new_values' => ['name' => $record->name],
                'changed_by' => $admin->id, 'changed_at' => now(),
            ]);
            $this->actingAs($admin)->post('/erp/security/audit/revisions/'.$revision->id.'/restore', ['reason' => 'Restore approved catalog master.'])->assertRedirect();
            $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, 'name' => $name, 'deleted_at' => null]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'document_revision.restored', 'auditable_type' => $type, 'auditable_id' => $record->id]);
        }
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 1]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'is_active' => 1]);
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'is_active' => 1]);
    }

    public function test_integration_clients_can_synchronize_tenant_revisions_and_field_diffs(): void
    {
        $company = Company::create(['name' => 'Revision Co', 'code' => 'REV-CO']);
        $otherCompany = Company::create(['name' => 'Other Revision Co', 'code' => 'REV-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        DocumentRevision::create([
            'company_id' => $company->id, 'document_type' => 'product', 'document_id' => 44,
            'version' => 1, 'old_values' => ['name' => 'Old'], 'new_values' => ['name' => 'New'],
            'changed_by' => $user->id, 'changed_at' => now()->subMinute(),
        ]);
        DocumentRevision::create([
            'company_id' => $otherCompany->id, 'document_type' => 'product', 'document_id' => 45,
            'version' => 1, 'old_values' => ['name' => 'Private'], 'new_values' => ['name' => 'Secret'],
            'changed_by' => null, 'changed_at' => now(),
        ]);

        Sanctum::actingAs($user, ['integration:read']);
        $response = $this->getJson('/api/integration/security/revisions?cursor_mode=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.feed', 'security.revisions')
            ->assertJsonPath('meta.has_more', false)->assertJsonPath('data.0.document_id', 44);
        $revisionId = $response->json('data.0.id');
        $this->getJson('/api/integration/security/revisions/'.$revisionId.'/diff')
            ->assertOk()->assertJsonPath('data.changes.name.from', 'Old')
            ->assertJsonPath('data.changes.name.to', 'New');
        $this->getJson('/api/integration/security/revisions/'.DocumentRevision::where('company_id', $otherCompany->id)->value('id').'/diff')
            ->assertNotFound();
    }

    public function test_integration_clients_can_restore_a_tenant_master_revision_with_write_scope(): void
    {
        $company = Company::create(['name' => 'API Restore Co', 'code' => 'API-RESTORE']);
        $otherCompany = Company::create(['name' => 'Other API Restore Co', 'code' => 'API-RESTORE-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Current supplier', 'is_active' => false]);
        $revision = DocumentRevision::create([
            'company_id' => $company->id, 'document_type' => (new Supplier)->getMorphClass(), 'document_id' => $supplier->id,
            'version' => 1, 'old_values' => ['name' => 'Restored supplier', 'is_active' => true],
            'new_values' => ['name' => 'Current supplier', 'is_active' => false], 'changed_by' => $user->id, 'changed_at' => now(),
        ]);
        $otherRevision = DocumentRevision::create([
            'company_id' => $otherCompany->id, 'document_type' => 'product', 'document_id' => 991,
            'version' => 1, 'old_values' => ['name' => 'Private'], 'new_values' => ['name' => 'Secret'],
            'changed_by' => null, 'changed_at' => now(),
        ]);

        Sanctum::actingAs($user, ['integration:read', 'integration:write']);
        $this->getJson('/api/integration/security/revisions/'.$revision->id.'/restore-preview')
            ->assertOk()
            ->assertJsonPath('data.safe_to_restore', true)
            ->assertJsonPath('data.document_type', (new Supplier)->getMorphClass())
            ->assertJsonPath('data.restorable_fields.name', 'Restored supplier')
            ->assertJsonPath('data.requires_review', false);

        $this->postJson('/api/integration/security/revisions/'.$revision->id.'/restore', [
            'reason' => 'Restore approved supplier master through integration.',
        ])->assertOk()->assertJsonPath('status', 'restored')->assertJsonPath('revision_id', $revision->id);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Restored supplier', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_revision.restored', 'auditable_type' => (new Supplier)->getMorphClass(), 'auditable_id' => $supplier->id,
        ]);
        $this->postJson('/api/integration/security/revisions/'.$otherRevision->id.'/restore', [
            'reason' => 'Should remain tenant isolated.',
        ])->assertNotFound();
    }

}
