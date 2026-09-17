<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['audit_logs', 'document_revisions', 'document_status_histories', 'user_activity_logs'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                });
            }

            $timeColumn = in_array($tableName, ['document_revisions', 'document_status_histories'], true) ? 'changed_at' : 'created_at';
            $indexName = $tableName.'_company_id_'.$timeColumn.'_index';
            if (!DB::selectOne('SHOW INDEX FROM '.$tableName.' WHERE Key_name = ?', [$indexName])) {
                Schema::table($tableName, function (Blueprint $table) use ($timeColumn, $indexName): void {
                    $table->index(['company_id', $timeColumn], $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'document_revisions', 'document_status_histories', 'user_activity_logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $timeColumn = in_array($tableName, ['document_revisions', 'document_status_histories'], true) ? 'changed_at' : 'created_at';
                $table->dropIndex($tableName.'_company_id_'.$timeColumn.'_index');
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
