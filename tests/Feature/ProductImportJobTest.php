<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\ProductImportJob;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_import_job_is_submitted_processed_and_audited(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Import Job Co', 'code' => 'IMPORT-JOB']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Import Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Import Each', 'code' => 'IMP-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Import Category', 'code' => 'IMP-CAT', 'status' => 1]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $csv = "name,supplier_id,unit_id,category_id,sku,purchase_price\nQueued product,{$supplier->id},{$unit->id},{$category->id},QUEUED-001,12.50\n";
        $created = $this->post('/api/inventory/product-import-jobs', [
            'external_reference' => 'IMPORT-JOB-001', 'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);
        $created->assertStatus(202)->assertJsonPath('status', 'queued');
        $jobId = $created->json('data.id');
        $this->postJson('/api/inventory/product-import-jobs', ['external_reference' => 'IMPORT-JOB-001'])->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $jobId);
        $this->assertDatabaseHas('product_import_jobs', ['id' => $jobId, 'company_id' => $company->id, 'status' => 'pending', 'max_attempts' => 3]);

        $this->artisan('erp:products:process-import-jobs')->assertExitCode(0);

        $this->assertDatabaseHas('products', ['company_id' => $company->id, 'sku' => 'QUEUED-001', 'name' => 'Queued product']);
        $this->assertDatabaseHas('product_import_jobs', ['id' => $jobId, 'status' => 'completed', 'row_count' => 1, 'imported_count' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_import_job.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_import_job.completed']);
        $this->getJson('/api/inventory/product-import-jobs/'.$jobId)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.attempts', 1);
        $this->assertSame(1, ProductImportJob::whereKey($jobId)->value('imported_count'));
    }

    public function test_pending_product_import_job_can_be_cancelled_and_private_file_is_removed(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Import Cancel Co', 'code' => 'IMPORT-CANCEL']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $created = $this->post('/api/inventory/product-import-jobs', ['file' => UploadedFile::fake()->createWithContent('cancel.csv', "name,supplier_id,unit_id,category_id\nPending,1,1,1\n")]);
        $created->assertStatus(202);
        $job = ProductImportJob::findOrFail($created->json('data.id'));
        Storage::disk('local')->assertExists($job->stored_path);

        $this->postJson('/api/inventory/product-import-jobs/'.$job->id.'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('product_import_jobs', ['id' => $job->id, 'status' => 'cancelled']);
        Storage::disk('local')->assertMissing($job->stored_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_import_job.cancelled']);
    }

    public function test_failed_product_import_job_can_be_requeued_within_attempt_limit(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Import Retry Co', 'code' => 'IMPORT-RETRY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $created = $this->post('/api/inventory/product-import-jobs', ['external_reference' => 'IMPORT-RETRY-001', 'max_attempts' => 3, 'file' => UploadedFile::fake()->createWithContent('invalid.csv', "name\nInvalid\n")]);
        $created->assertStatus(202);
        $jobId = $created->json('data.id');
        $this->artisan('erp:products:process-import-jobs')->assertExitCode(0);
        $this->assertDatabaseHas('product_import_jobs', ['id' => $jobId, 'status' => 'failed', 'attempts' => 1]);

        $this->postJson('/api/inventory/product-import-jobs/'.$jobId.'/retry')->assertOk()->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('product_import_jobs', ['id' => $jobId, 'status' => 'pending', 'attempts' => 1]);
        $this->artisan('erp:products:process-import-jobs')->assertExitCode(0);
        $this->assertDatabaseHas('product_import_jobs', ['id' => $jobId, 'status' => 'failed', 'attempts' => 2]);
    }
}
