<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_adjustments', 'inventory_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('rejection_reason')->nullable()->after('description');
                $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['inventory_adjustments', 'inventory_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn(['rejection_reason', 'rejected_by', 'rejected_at']);
            });
        }
    }
};
