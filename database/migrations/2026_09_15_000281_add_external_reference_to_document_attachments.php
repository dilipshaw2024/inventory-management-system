<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('attachable_id');
            $table->unique(['company_id', 'external_reference'], 'document_attachments_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropUnique('document_attachments_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
