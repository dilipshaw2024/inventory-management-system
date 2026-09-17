<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('product_id')->constrained('departments')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->after('department_id')->constrained('cost_centers')->nullOnDelete();
            $table->index(['department_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['department_id', 'cost_center_id']);
            $table->dropColumn(['department_id', 'cost_center_id']);
        });
    }
};
