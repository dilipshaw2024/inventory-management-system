<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_requests', 'maintenance_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->string('external_reference', 150)->nullable()->after('company_id');
                $table->unique(['company_id', 'external_reference'], $tableName.'_company_external_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['service_requests', 'maintenance_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_company_external_unique');
                $table->dropColumn('external_reference');
            });
        }
    }
};
