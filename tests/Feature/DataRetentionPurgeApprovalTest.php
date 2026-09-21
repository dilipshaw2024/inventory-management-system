<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionPurgeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataRetentionPurgeApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_purge_requires_approved_one_time_request(): void
    {
        $company = Company::create(['name' => 'Retention Co', 'code' => 'RETENTION-CO']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $policy = DataRetentionPolicy::create([
            'company_id' => $company->id, 'name' => 'Audit purge', 'record_type' => 'audit_logs',
            'retention_days' => 30, 'archive_enabled' => true, 'purge_enabled' => true, 'is_active' => true,
        ]);

        $this->artisan('erp:retention:process', ['--purge' => true])->assertExitCode(1);
        $purge = DataRetentionPurgeRequest::create([
            'company_id' => $company->id, 'policy_id' => $policy->id, 'cutoff_at' => now()->subDays(30),
            'status' => 'approved', 'reason' => 'Approved retention disposal window.',
            'requested_by' => $requester->id, 'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $this->artisan('erp:retention:process', ['--purge' => true, '--purge-request' => $purge->id])->assertExitCode(0);
        $this->assertDatabaseHas('data_retention_purge_requests', ['id' => $purge->id, 'status' => 'consumed']);
    }

    public function test_integration_clients_can_request_and_independently_decide_purge(): void
    {
        $company = Company::create(['name' => 'Retention API Co', 'code' => 'RETENTION-API']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $policy = DataRetentionPolicy::create([
            'company_id' => $company->id, 'name' => 'API audit purge', 'record_type' => 'audit_logs',
            'retention_days' => 30, 'archive_enabled' => true, 'purge_enabled' => true, 'is_active' => true,
        ]);
        Sanctum::actingAs($requester, ['integration:write']);
        $created = $this->postJson('/api/integration/security/retention/purge-requests', [
            'policy_id' => $policy->id, 'reason' => 'Approved API retention disposal window.',
        ])->assertCreated()->assertJsonPath('status', 'pending');
        $purgeId = $created->json('data.id');
        Sanctum::actingAs($approver, ['integration:write']);
        $this->postJson('/api/integration/security/retention/purge-requests/'.$purgeId.'/approve', [
            'decision_reason' => 'Independent retention approval completed.',
        ])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('data_retention_purge_requests', ['id' => $purgeId, 'approved_by' => $approver->id]);
    }

    public function test_approved_attachment_retention_purge_archives_and_removes_private_file(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Attachment Retention Co', 'code' => 'RETENTION-ATTACH']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $path = 'erp-attachments/old-retention.pdf';
        Storage::disk('local')->put($path, 'old private content');
        $attachment = DocumentAttachment::create(['company_id' => $company->id, 'attachable_type' => Company::class, 'attachable_id' => $company->id, 'original_name' => 'old-retention.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 19, 'uploaded_by' => $requester->id, 'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)]);
        $policy = DataRetentionPolicy::create(['company_id' => $company->id, 'name' => 'Attachment purge', 'record_type' => 'document_attachments', 'retention_days' => 30, 'archive_enabled' => true, 'purge_enabled' => true, 'is_active' => true]);
        $purge = DataRetentionPurgeRequest::create(['company_id' => $company->id, 'policy_id' => $policy->id, 'cutoff_at' => now()->subDays(30), 'status' => 'approved', 'reason' => 'Approved attachment disposal window.', 'requested_by' => $requester->id, 'approved_by' => $approver->id, 'approved_at' => now()]);

        $this->artisan('erp:retention:process', ['--purge' => true, '--purge-request' => $purge->id])->assertExitCode(0);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseHas('data_retention_archives', ['company_id' => $company->id, 'record_type' => 'document_attachments', 'record_id' => $attachment->id]);
        $this->assertDatabaseHas('data_retention_purge_requests', ['id' => $purge->id, 'status' => 'consumed']);
    }
}
