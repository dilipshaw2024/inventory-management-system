<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->after('batch_id')->constrained('inventory_serials')->nullOnDelete();
            $table->index(['company_id', 'product_id', 'batch_id', 'serial_id'], 'status_transfers_traceability_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->dropIndex('status_transfers_traceability_idx');
            $table->dropForeign(['serial_id']);
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['batch_id', 'serial_id']);
        });
    }
};
