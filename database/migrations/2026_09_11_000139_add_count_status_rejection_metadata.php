<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['stock_counts', 'inventory_status_transfers'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'rejection_reason')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    // Legacy status-transfer rows use reason rather than
                    // description; avoid driver-sensitive positional clauses.
                    $table->text('rejection_reason')->nullable();
                });
            }
            if (!Schema::hasColumn($tableName, 'rejected_by')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                });
            }
            if (!Schema::hasColumn($tableName, 'rejected_at')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->timestamp('rejected_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['stock_counts', 'inventory_status_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn(['rejection_reason', 'rejected_by', 'rejected_at']);
            });
        }
    }
};
