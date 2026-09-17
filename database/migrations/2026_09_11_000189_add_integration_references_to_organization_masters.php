<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = ['branches' => ['company_id', 'external_reference'], 'warehouses' => ['branch_id', 'external_reference'], 'stores' => ['branch_id', 'external_reference'], 'departments' => ['company_id', 'external_reference'], 'inventory_locations' => ['warehouse_id', 'external_reference']];
        foreach ($indexes as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                $table->string('external_reference', 150)->nullable()->after('code');
                $table->unique($columns, $tableName.'_external_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['branches', 'warehouses', 'stores', 'departments', 'inventory_locations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_external_unique');
                $table->dropColumn('external_reference');
            });
        }
    }
};
