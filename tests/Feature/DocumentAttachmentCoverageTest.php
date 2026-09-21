<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Brand;
use App\Models\DocumentAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentAttachmentCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_document_attachment_is_authorized_and_stored_privately(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Attachment Co', 'code' => 'ATTACHMENT-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Attachment Brand', 'code' => 'ATTACH-BRAND', 'is_active' => true]);
        $permission = Permission::create(['code' => 'sales.manage', 'name' => 'Manage sales', 'module' => 'sales']);
        $role = Role::create(['code' => 'attachment-sales', 'name' => 'Attachment sales', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Attachment customer', 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-ATTACHMENT-1', 'date' => '2026-09-15', 'status' => 'draft']);

        $this->actingAs($user);
        $response = $this->post('/erp/attachments', [
            'attachable_type' => 'sales_order',
            'attachable_id' => $order->id,
            'attachment_type' => 'document',
            'title' => 'Customer order document',
            'file' => UploadedFile::fake()->create('order.pdf', 10, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $attachment = DocumentAttachment::firstOrFail();
        $this->assertSame(SalesOrder::class, $attachment->attachable_type);
        $this->assertSame($company->id, $attachment->company_id);
        Storage::disk('local')->assertExists($attachment->stored_path);

        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/attachments?attachable_type=sales_order&attachable_id='.$order->id)
            ->assertOk()
            ->assertJsonPath('data.0.attachable_id', $order->id)
            ->assertJsonPath('data.0.attachment_type', 'document');

        Sanctum::actingAs($user, ['inventory:write']);
        $apiPayload = ['attachable_type' => 'sales_order', 'attachable_id' => $order->id, 'external_reference' => 'ERP-DOC-1', 'attachment_type' => 'document', 'file' => UploadedFile::fake()->create('api-order.pdf', 10, 'application/pdf')];
        $created = $this->post('/api/inventory/attachments', $apiPayload);
        $created->assertCreated()->assertJsonPath('data.external_reference', 'ERP-DOC-1');
        $duplicate = $this->post('/api/inventory/attachments', $apiPayload);
        $duplicate->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('status', 'duplicate_ignored');
        $brandUpload = $this->post('/api/inventory/attachments', ['attachable_type' => 'brand', 'attachable_id' => $brand->id, 'external_reference' => 'BRAND-LOGO-1', 'attachment_type' => 'image', 'is_primary' => true, 'file' => UploadedFile::fake()->image('brand-logo.png')]);
        $brandUpload->assertCreated()->assertJsonPath('data.attachable_id', $brand->id);
        $this->assertSame(1, (int) $brandUpload->json('data.is_primary'));
        Sanctum::actingAs($user, ['inventory:read']);
        $this->get('/api/inventory/attachments/'.$attachment->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
