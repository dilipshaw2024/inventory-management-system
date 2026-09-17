<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('inventory_batches')->nullOnDelete();
            $table->index(['product_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['product_id', 'batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
