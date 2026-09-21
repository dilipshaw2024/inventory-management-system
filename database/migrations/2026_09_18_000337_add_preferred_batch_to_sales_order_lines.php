<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('inventory_batches')->nullOnDelete();
            $table->index(['product_id', 'batch_id'], 'sales_order_lines_batch_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropIndex('sales_order_lines_batch_index');
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
