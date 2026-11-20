<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('is_primary');
            $table->string('alt_text', 255)->nullable()->after('sort_order');
            $table->index(['attachable_type', 'attachable_id', 'attachment_type', 'sort_order'], 'attachments_gallery_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropIndex('attachments_gallery_order_idx');
            $table->dropColumn(['sort_order', 'alt_text']);
        });
    }
};
