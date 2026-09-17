<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'attachment_type' => fn (Blueprint $table) => $table->string('attachment_type')->default('document'),
            'title' => fn (Blueprint $table) => $table->string('title')->nullable(),
            'version' => fn (Blueprint $table) => $table->unsignedInteger('version')->default(1),
            'is_primary' => fn (Blueprint $table) => $table->boolean('is_primary')->default(false),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('document_attachments', $column)) {
                Schema::table('document_attachments', $definition);
            }
        }
        if (!DB::selectOne('SHOW INDEX FROM document_attachments WHERE Key_name = ?', ['attachments_media_type_idx'])) {
            Schema::table('document_attachments', function (Blueprint $table): void {
                $table->index(['attachable_type', 'attachable_id', 'attachment_type'], 'attachments_media_type_idx');
            });
        }
    }
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropIndex(['attachable_type', 'attachable_id', 'attachment_type']);
            $table->dropColumn(['attachment_type', 'title', 'version', 'is_primary']);
        });
    }
};
