<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_assets', 'service_requests', 'maintenance_orders', 'maintenance_parts', 'service_technicians', 'service_maintenance_schedules', 'warranty_claims', 'asset_spare_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['service_assets', 'service_requests', 'maintenance_orders', 'maintenance_parts', 'service_technicians', 'service_maintenance_schedules', 'warranty_claims', 'asset_spare_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex([$tableName.'_company_id_index']);
                $table->dropColumn('company_id');
            });
        }
    }
};
