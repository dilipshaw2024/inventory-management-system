<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('inventory_batches')->nullOnDelete();
            $table->index(['product_id', 'location_id', 'batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['product_id', 'location_id', 'batch_id', 'status']);
            $table->dropColumn('batch_id');
        });
    }
};
