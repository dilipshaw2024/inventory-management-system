<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_retention_archives', function (Blueprint $table): void {
            $table->string('external_disk')->nullable()->after('payload_hash');
            $table->string('external_path')->nullable()->after('external_disk');
            $table->unsignedBigInteger('external_bytes')->nullable()->after('external_path');
            $table->string('external_hash', 64)->nullable()->after('external_bytes');
            $table->index(['external_disk', 'external_path'], 'retention_archive_external_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('data_retention_archives', function (Blueprint $table): void {
            $table->dropIndex('retention_archive_external_lookup');
            $table->dropColumn(['external_disk', 'external_path', 'external_bytes', 'external_hash']);
        });
    }
};
