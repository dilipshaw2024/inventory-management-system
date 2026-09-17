<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bills_of_materials', 'bom_lines', 'bom_byproducts', 'work_centers', 'routings', 'routing_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['bills_of_materials', 'bom_lines', 'bom_byproducts', 'work_centers', 'routings', 'routing_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex([$tableName.'_company_id_index']);
                $table->dropColumn('company_id');
            });
        }
    }
};
