<?php

namespace Tests\Unit;

use App\Services\RetentionArchiveStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetentionArchiveStorageTest extends TestCase
{
    public function test_configured_archive_disk_writes_a_content_addressed_copy(): void
    {
        Storage::fake('retention-test');
        config(['retention.archive_disk' => 'retention-test']);
        $payload = ['record_id' => 7, 'name' => 'Immutable record'];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));

        $metadata = app(RetentionArchiveStorage::class)->store(3, 'audit_logs', 7, $payload, $hash);

        $this->assertSame('retention-test', $metadata['external_disk']);
        $this->assertSame($hash, $metadata['external_hash']);
        Storage::disk('retention-test')->assertExists($metadata['external_path']);
        $this->assertSame($metadata['external_bytes'], strlen(Storage::disk('retention-test')->get($metadata['external_path'])));
    }

    public function test_existing_archive_object_is_not_overwritten(): void
    {
        Storage::fake('retention-test');
        config(['retention.archive_disk' => 'retention-test']);
        $payload = ['record_id' => 8, 'name' => 'Stable record'];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
        $service = app(RetentionArchiveStorage::class);

        $first = $service->store(3, 'audit_logs', 8, $payload, $hash);
        $second = $service->store(3, 'audit_logs', 8, $payload, $hash);

        $this->assertSame($first, $second);
    }

    public function test_hash_mismatch_refuses_to_overwrite_archive_object(): void
    {
        Storage::fake('retention-test');
        config(['retention.archive_disk' => 'retention-test']);
        $payload = ['record_id' => 9, 'name' => 'Original'];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
        $service = app(RetentionArchiveStorage::class);
        $metadata = $service->store(3, 'audit_logs', 9, $payload, $hash);

        $this->expectException(\RuntimeException::class);
        $service->store(3, 'audit_logs', 9, ['record_id' => 9, 'name' => 'Changed'], $hash);
        Storage::disk('retention-test')->assertExists($metadata['external_path']);
    }
}
