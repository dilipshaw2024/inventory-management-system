<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class RetentionArchiveStorage
{
    /**
     * Store a content-addressed archive copy when an external archive disk is configured.
     * Existing objects are never overwritten; their content hash must match.
     */
    public function store(int $companyId, string $recordType, int $recordId, array $payload, string $payloadHash): ?array
    {
        $diskName = config('retention.archive_disk');
        if (!$diskName) return null;

        $contents = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $computedHash = hash('sha256', $contents);
        if (!hash_equals($payloadHash, $computedHash)) {
            throw new \RuntimeException('Retention archive payload hash does not match the payload; refusing to store it.');
        }
        $path = sprintf(
            'erp-retention/%d/%s/%d-%s.json',
            $companyId,
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $recordType),
            $recordId,
            $payloadHash
        );
        $disk = Storage::disk($diskName);
        if ($disk->exists($path)) {
            $existingHash = hash('sha256', (string) $disk->get($path));
            if (!hash_equals($payloadHash, $existingHash)) {
                throw new \RuntimeException('Retention archive object hash mismatch; refusing to overwrite an existing archive.');
            }
        } else {
            $disk->put($path, $contents);
        }

        return [
            'external_disk' => $diskName,
            'external_path' => $path,
            'external_bytes' => strlen($contents),
            'external_hash' => $payloadHash,
        ];
    }
}
