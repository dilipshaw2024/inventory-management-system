<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DocumentAttachment;
use App\Models\ProductBundleComponent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentAttachmentCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_screen_can_upload_and_render_private_media(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Variant Media Co', 'code' => 'VARIANT-MEDIA']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'inventory.view', 'name' => 'View inventory', 'module' => 'inventory']);
        $role = Role::create(['code' => 'variant-media', 'name' => 'Variant media', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Variant supplier', 'is_active' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Variant category', 'status' => 1]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Piece', 'code' => 'PC-VARIANT', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $productDefaults = ['company_id' => $company->id, 'supplier_id' => $supplier->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'product_type' => 'stock', 'is_stock_item' => true, 'status' => 1, 'lifecycle_status' => 'active', 'can_purchase' => true, 'can_sell' => true, 'quantity' => 0];
        $parent = Product::create($productDefaults + ['name' => 'Variant parent']);
        $variant = Product::create($productDefaults + ['parent_product_id' => $parent->id, 'is_variant' => true, 'name' => 'Variant red']);

        $this->actingAs($user)->get('/product/variants')->assertOk()->assertSee('Variant red');
        $this->actingAs($user)->post('/erp/attachments', [
            'attachable_type' => 'product', 'attachable_id' => $variant->id, 'attachment_type' => 'image',
            'title' => 'Red variant image', 'is_primary' => true, 'file' => UploadedFile::fake()->image('variant-red.png'),
        ])->assertRedirect();
        $attachment = DocumentAttachment::where('attachable_id', $variant->id)->firstOrFail();
        $this->assertSame(Product::class, $attachment->attachable_type);
        $this->assertTrue((bool) $attachment->is_primary);
        Storage::disk('local')->assertExists($attachment->stored_path);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
        $this->getJson('/api/inventory/products/'.$variant->id.'/media')->assertOk()->assertJsonPath('data.0.alt_text', null)->assertJsonPath('data.0.sort_order', 0);
        $this->patchJson('/api/inventory/products/'.$variant->id.'/media/'.$attachment->id, ['alt_text' => 'Red variant product image', 'sort_order' => 2, 'publication_channels' => ['Web', 'mobile'], 'is_published' => true])->assertOk()->assertJsonPath('data.alt_text', 'Red variant product image')->assertJsonPath('data.sort_order', 2)->assertJsonPath('data.publication_channels.0', 'web');
        $this->getJson('/api/inventory/products/'.$variant->id.'/media?channel=web')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory/products/'.$variant->id.'/media?channel=storefront')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory/products?catalog=1&include_media=1&channel=web&q=Variant%20red')->assertOk()->assertJsonPath('data.0.attachments.0.alt_text', 'Red variant product image');
        $catalogResponse = $this->getJson('/api/inventory/products?catalog=1&channel=storefront&q=Variant%20red')->assertOk()->assertJsonCount(0, 'data.0.attachments');
        $catalogProduct = $catalogResponse->json('data.0');
        $this->assertIsArray($catalogProduct);
        $this->assertArrayNotHasKey('buying_price', $catalogProduct);
        $this->assertArrayNotHasKey('quantity', $catalogProduct);
        $component = Product::create($productDefaults + ['name' => 'Bundle component']);
        $bundle = Product::create($productDefaults + ['name' => 'Bundle red', 'product_type' => 'bundle']);
        ProductBundleComponent::create(['company_id' => $company->id, 'bundle_product_id' => $bundle->id, 'component_product_id' => $component->id, 'quantity' => 2, 'sort_order' => 1]);
        $this->getJson('/api/inventory/products?catalog=1&q=Bundle%20red')->assertOk()->assertJsonPath('data.0.bundle_components.0.quantity', '2.000000')->assertJsonPath('data.0.bundle_components.0.component_product.name', 'Bundle component');
        $this->actingAs($user)->get('/product/variants')->assertOk()->assertSee('Red variant image');
    }

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

        Sanctum::actingAs($user, ['sales:read']);
        $this->getJson('/api/inventory/attachments?attachable_type=sales_order&attachable_id='.$order->id)
            ->assertOk()
            ->assertJsonPath('data.0.attachable_id', $order->id)
            ->assertJsonPath('data.0.attachment_type', 'document');

        Sanctum::actingAs($user, ['sales:write']);
        $apiPayload = ['attachable_type' => 'sales_order', 'attachable_id' => $order->id, 'external_reference' => 'ERP-DOC-1', 'attachment_type' => 'document', 'file' => UploadedFile::fake()->create('api-order.pdf', 10, 'application/pdf')];
        $created = $this->post('/api/inventory/attachments', $apiPayload);
        $created->assertCreated()->assertJsonPath('data.external_reference', 'ERP-DOC-1');
        $duplicate = $this->post('/api/inventory/attachments', $apiPayload);
        $duplicate->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('status', 'duplicate_ignored');
        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/attachments?attachable_type=sales_order&attachable_id='.$order->id)->assertForbidden();
        Sanctum::actingAs($user, ['inventory:write']);
        $brandUpload = $this->post('/api/inventory/attachments', ['attachable_type' => 'brand', 'attachable_id' => $brand->id, 'external_reference' => 'BRAND-LOGO-1', 'attachment_type' => 'image', 'is_primary' => true, 'file' => UploadedFile::fake()->image('brand-logo.png')]);
        $brandUpload->assertCreated()->assertJsonPath('data.attachable_id', $brand->id);
        $this->assertSame(1, (int) $brandUpload->json('data.is_primary'));
        Sanctum::actingAs($user, ['sales:read']);
        $this->get('/api/inventory/attachments/'.$attachment->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
