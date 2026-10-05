<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stock_reservations', 'serial_id')) return;
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->foreignId('serial_id')->nullable()->after('batch_id')->constrained('inventory_serials')->nullOnDelete();
            $table->index(['product_id', 'location_id', 'batch_id', 'serial_id', 'status'], 'stock_reservations_serial_allocation_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('stock_reservations', 'serial_id')) return;
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropIndex('stock_reservations_serial_allocation_index');
            $table->dropForeign(['serial_id']);
            $table->dropColumn('serial_id');
        });
    }
};
