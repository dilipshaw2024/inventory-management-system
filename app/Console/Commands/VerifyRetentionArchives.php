<?php

namespace App\Console\Commands;

use App\Models\DataRetentionArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class VerifyRetentionArchives extends Command
{
    protected $signature = 'erp:retention:verify-archives {--company=}';
    protected $description = 'Verify the integrity and availability of external retention archive objects';

    public function handle(): int
    {
        $query = DataRetentionArchive::query()->whereNotNull('external_path')->orderBy('id');
        if ($this->option('company') !== null) $query->where('company_id', (int) $this->option('company'));
        $checked = 0; $invalid = 0;

        foreach ($query->cursor() as $archive) {
            $checked++;
            try {
                $disk = Storage::disk((string) $archive->external_disk);
                if (!$disk->exists($archive->external_path)) {
                    $this->error('Missing retention archive object at '.$archive->external_path.'.');
                    $invalid++;
                    continue;
                }
                $actual = hash('sha256', (string) $disk->get($archive->external_path));
                $expected = (string) ($archive->external_hash ?: $archive->payload_hash);
                if (!hash_equals($expected, $actual)) {
                    $this->error('Invalid retention archive hash at archive ID '.$archive->id.'.');
                    $invalid++;
                }
            } catch (\Throwable $exception) {
                $this->error('Unable to verify archive ID '.$archive->id.': '.$exception->getMessage());
                $invalid++;
            }
        }

        $this->info('Checked '.$checked.' external retention archives; invalid: '.$invalid.'.');
        return $invalid ? self::FAILURE : self::SUCCESS;
    }
}
