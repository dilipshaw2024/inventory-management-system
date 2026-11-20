<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->boolean('is_published')->default(true)->after('is_primary');
            $table->json('publication_channels')->nullable()->after('is_published');
            $table->index(['attachable_type', 'attachable_id', 'attachment_type', 'is_published'], 'attachments_publication_idx');
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropIndex('attachments_publication_idx');
            $table->dropColumn(['is_published', 'publication_channels']);
        });
    }
};
