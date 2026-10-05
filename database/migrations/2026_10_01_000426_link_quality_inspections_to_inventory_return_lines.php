<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void {
            $table->foreignId('quality_inspection_id')->nullable()->after('product_id')->constrained('quality_inspections')->nullOnDelete();
            $table->index(['return_id', 'quality_inspection_id'], 'inventory_return_lines_quality_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void {
            $table->dropForeign(['quality_inspection_id']);
            $table->dropIndex('inventory_return_lines_quality_idx');
            $table->dropColumn('quality_inspection_id');
        });
    }
};
