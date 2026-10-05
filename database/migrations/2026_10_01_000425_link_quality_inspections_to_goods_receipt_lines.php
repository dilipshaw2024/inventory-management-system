<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('quality_inspection_id')->nullable()->after('product_id')->constrained('quality_inspections')->nullOnDelete();
            $table->index(['goods_receipt_id', 'quality_inspection_id'], 'goods_receipt_lines_quality_idx');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['quality_inspection_id']);
            $table->dropIndex('goods_receipt_lines_quality_idx');
            $table->dropColumn('quality_inspection_id');
        });
    }
};
