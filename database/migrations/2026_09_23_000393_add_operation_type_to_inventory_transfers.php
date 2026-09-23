<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inventory_transfers', 'operation_type')) {
            Schema::table('inventory_transfers', function (Blueprint $table): void {
                $table->string('operation_type', 30)->default('transfer')->after('transfer_no');
                $table->index(['company_id', 'operation_type', 'status'], 'inventory_transfer_operation_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_transfers', 'operation_type')) {
            Schema::table('inventory_transfers', function (Blueprint $table): void {
                $table->dropIndex('inventory_transfer_operation_status_idx');
                $table->dropColumn('operation_type');
            });
        }
    }
};
