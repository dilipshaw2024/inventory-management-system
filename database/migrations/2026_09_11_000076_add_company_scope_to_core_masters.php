<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'suppliers', 'customers'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                });
            }

            // The legacy products table uses status; suppliers/customers use
            // is_active. Also tolerate a partially applied MySQL DDL change.
            $activeColumn = $tableName === 'products' && Schema::hasColumn($tableName, 'status') ? 'status' : 'is_active';
            $indexName = $tableName.'_company_id_'.$activeColumn.'_index';
            $indexExists = DB::selectOne('SHOW INDEX FROM '.$tableName.' WHERE Key_name = ?', [$indexName]);
            if (!$indexExists) {
                Schema::table($tableName, function (Blueprint $table) use ($activeColumn, $indexName): void {
                    $table->index(['company_id', $activeColumn], $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['products', 'suppliers', 'customers'] as $tableName) {
            $activeColumn = $tableName === 'products' && Schema::hasColumn($tableName, 'status') ? 'status' : 'is_active';
            $indexName = $tableName.'_company_id_'.$activeColumn.'_index';
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex($indexName);
                $table->dropColumn('company_id');
            });
        }
    }
};
