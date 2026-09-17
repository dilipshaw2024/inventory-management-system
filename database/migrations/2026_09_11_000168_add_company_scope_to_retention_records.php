<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['data_retention_policies', 'data_retention_holds', 'data_retention_archives'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            });
        }
        Schema::table('data_retention_archives', function (Blueprint $table): void {
            $table->dropUnique(['record_type', 'record_id']);
            $table->unique(['company_id', 'record_type', 'record_id'], 'retention_archives_company_record_unique');
        });
    }

    public function down(): void
    {
        Schema::table('data_retention_archives', function (Blueprint $table): void {
            $table->dropUnique('retention_archives_company_record_unique');
            $table->unique(['record_type', 'record_id']);
        });
        foreach (['data_retention_policies', 'data_retention_holds', 'data_retention_archives'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'created_at']);
                $table->dropColumn('company_id');
            });
        }
    }
};
