<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->decimal('sample_quantity', 20, 8)->nullable()->after('quantity');
            $table->index(['company_id', 'sample_quantity'], 'quality_inspections_sample_quantity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropIndex('quality_inspections_sample_quantity_idx');
            $table->dropColumn('sample_quantity');
        });
    }
};
