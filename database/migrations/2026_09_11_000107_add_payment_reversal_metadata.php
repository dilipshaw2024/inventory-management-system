<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'supplier_payments'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'is_reversed')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    // Legacy customer payments use paid_status, not status;
                    // avoid positional clauses so both schemas are supported.
                    $table->boolean('is_reversed')->default(false);
                });
            }
            if (!Schema::hasColumn($tableName, 'reversed_at')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->timestamp('reversed_at')->nullable();
                });
            }
            if (!Schema::hasColumn($tableName, 'reversed_by')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                });
            }
            if (!Schema::hasColumn($tableName, 'reversal_reason')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('reversal_reason')->nullable();
                });
            }
            $indexName = $tableName.'_company_reversed_idx';
            if (Schema::hasColumn($tableName, 'company_id') && !DB::selectOne('SHOW INDEX FROM '.$tableName.' WHERE Key_name = ?', [$indexName])) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->index(['company_id', 'is_reversed'], $indexName);
                });
            }
        }
    }
    public function down(): void
    {
        foreach (['payments', 'supplier_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex([$tableName === 'payments' ? 'company_id' : 'company_id', 'is_reversed']);
                $table->dropForeign(['reversed_by']);
                $table->dropColumn(['is_reversed', 'reversed_at', 'reversed_by', 'reversal_reason']);
            });
        }
    }
};
